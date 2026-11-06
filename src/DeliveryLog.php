<?php

declare(strict_types=1);

namespace MailcowDeliverability;

use MailcowDeliverability\Analysis\ProviderMap;
use MailcowDeliverability\Analysis\TimeWindow;
use MailcowDeliverability\Log\DeliveryEvent;
use MailcowDeliverability\Log\LogSource;
use MailcowDeliverability\Log\PostfixLogParser;

/**
 * Loads and caches parsed delivery events from the configured log source.
 * The cache is keyed on each file's size and mtime, so a log that is still
 * being written to is re-read on the next call.
 */
final class DeliveryLog
{
    /** @var list<DeliveryEvent>|null */
    private ?array $events = null;

    private string $fingerprint = '';

    private int $linesRead = 0;

    public function __construct(
        private readonly LogSource $source,
        private readonly PostfixLogParser $parser,
        private readonly ProviderMap $providers,
        private readonly Config $config,
    ) {
    }

    public static function fromConfig(Config $config): self
    {
        $providers = ProviderMap::fromFile($config->providerMapFile);
        $tz = new \DateTimeZone($config->timezone);

        return new self(new LogSource($config->logPath), new PostfixLogParser($providers, $tz), $providers, $config);
    }

    /**
     * @return list<DeliveryEvent>
     */
    public function events(): array
    {
        $files = $this->source->files();
        clearstatcache();
        $fp = '';
        foreach ($files as $f) {
            $fp .= $f.':'.filesize($f).':'.filemtime($f).';';
        }
        if (null === $this->events || $fp !== $this->fingerprint) {
            $events = [];
            $lines = 0;
            foreach ($this->source->lines() as $line) {
                ++$lines;
                $e = $this->parser->parseLine($line);
                if (null !== $e) {
                    $events[] = $e;
                }
            }
            $this->events = $events;
            $this->linesRead = $lines;
            $this->fingerprint = $fp;
        }

        return $this->events;
    }

    /**
     * @return array{events: list<DeliveryEvent>, from: ?\DateTimeImmutable, to: ?\DateTimeImmutable}
     */
    public function window(string $since, string $anchor = 'latest'): array
    {
        return TimeWindow::parse($since)->apply($this->events(), $anchor);
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(): array
    {
        $events = $this->events();
        $first = null;
        $last = null;
        $untimed = 0;
        foreach ($events as $e) {
            if (null === $e->time) {
                ++$untimed;
                continue;
            }
            $first = null === $first || $e->time < $first ? $e->time : $first;
            $last = null === $last || $e->time > $last ? $e->time : $last;
        }
        $files = [];
        foreach ($this->source->files() as $f) {
            $files[] = ['file' => $f, 'bytes' => filesize($f), 'gzip' => str_ends_with(strtolower($f), '.gz')];
        }

        return [
            'log_path' => $this->source->path(),
            'mode' => $this->source->isDirectory() ? 'directory' : 'file',
            'using_bundled_sample' => $this->config->usingSample,
            'files' => $files,
            'lines_read' => $this->linesRead,
            'delivery_events' => \count($events),
            'events_without_timestamp' => $untimed,
            'first_event' => $first?->format(\DATE_ATOM),
            'last_event' => $last?->format(\DATE_ATOM),
            'timezone_for_syslog_timestamps' => $this->config->timezone,
            'provider_map_file' => $this->providers->source(),
            'provider_mappings' => $this->providers->count(),
        ];
    }
}
