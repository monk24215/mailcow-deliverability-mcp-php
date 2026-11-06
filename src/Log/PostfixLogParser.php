<?php

declare(strict_types=1);

namespace MailcowDeliverability\Log;

use MailcowDeliverability\Analysis\ProviderMap;

/**
 * Parses Postfix delivery lines into DeliveryEvent objects.
 *
 * A delivery line looks like:
 *   postfix/smtp[123]: 4A1B2C3D4E: to=<x@example.com>, relay=mx.example.com[192.0.2.1]:25,
 *   delay=1.2, delays=0.1/0/0.5/0.6, dsn=2.0.0, status=sent (250 2.0.0 OK)
 *
 * Accepted timestamp prefixes:
 *   - RFC 3339 (rsyslog high-precision, docker logs --timestamps): 2026-11-03T09:12:01.123456+00:00
 *   - Traditional syslog without a year: "Nov  3 09:12:01"
 *   - Mailcow API export as flattened by the TS project: ISO 8601 with "Z"
 * Lines with no recognisable timestamp are still parsed, with a null time.
 */
final class PostfixLogParser
{
    /** Same pattern as DELIVERY_RE in the TS project, minus the relay group (extracted separately below). */
    private const DELIVERY_RE = '/(?:^|\s)(?<qid>[A-F0-9]{8,20}|[0-9a-zA-Z]{10,16}):\s+to=<(?<to>[^>]*)>.*?dsn=(?<dsn>\d\.\d\.\d+),\s*status=(?<status>\w+)\s*(?<reason>\(.*\))?/';

    private const RELAY_RE = '/\brelay=(?<relay>[^,\s]+)/';

    private const RFC3339_RE = '/^(?<ts>\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)\s/';

    private const SYSLOG_RE = '/^(?<mon>Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)\s+(?<day>\d{1,2})\s+(?<time>\d{2}:\d{2}:\d{2})\s/';

    private readonly \DateTimeZone $timezone;

    private readonly \DateTimeImmutable $reference;

    /**
     * @param ?\DateTimeZone      $timezone  zone for timestamps that carry none (default UTC)
     * @param ?\DateTimeImmutable $reference "now", used to infer the year of syslog timestamps
     */
    public function __construct(
        private readonly ProviderMap $providers = new ProviderMap(),
        ?\DateTimeZone $timezone = null,
        ?\DateTimeImmutable $reference = null,
    ) {
        $this->timezone = $timezone ?? new \DateTimeZone('UTC');
        $this->reference = $reference ?? new \DateTimeImmutable('now', $this->timezone);
    }

    /**
     * @param iterable<string> $lines
     *
     * @return list<DeliveryEvent>
     */
    public function parseLines(iterable $lines): array
    {
        $events = [];
        foreach ($lines as $line) {
            $event = $this->parseLine($line);
            if (null !== $event) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * @return list<DeliveryEvent>
     */
    public function parseText(string $text): array
    {
        return $this->parseLines(preg_split('/\R/', $text) ?: []);
    }

    public function parseLine(string $line): ?DeliveryEvent
    {
        if (!str_contains($line, 'status=') || !str_contains($line, 'to=<')) {
            return null;
        }
        if (!preg_match(self::DELIVERY_RE, $line, $m)) {
            return null;
        }

        $to = strtolower($m['to']);
        $at = strrpos($to, '@');
        $domain = false === $at ? 'unknown' : substr($to, $at + 1);
        $rawStatus = strtolower($m['status']);
        $status = \in_array($rawStatus, DeliveryEvent::STATUSES, true) ? $rawStatus : 'other';
        $reason = trim($m['reason'] ?? '');
        if (str_starts_with($reason, '(')) {
            $reason = substr($reason, 1);
        }
        if (str_ends_with($reason, ')')) {
            $reason = substr($reason, 0, -1);
        }
        $relay = preg_match(self::RELAY_RE, $line, $r) ? $r['relay'] : '';

        return new DeliveryEvent(
            time: $this->parseTimestamp($line),
            queueId: $m['qid'],
            recipient: $to,
            recipientDomain: $domain,
            provider: $this->providers->providerFor($domain),
            status: $status,
            dsn: $m['dsn'],
            reason: trim($reason),
            relay: $relay,
        );
    }

    public function parseTimestamp(string $line): ?\DateTimeImmutable
    {
        if (preg_match(self::RFC3339_RE, $line, $m)) {
            try {
                return new \DateTimeImmutable($m['ts'], $this->timezone);
            } catch (\Exception) {
                return null;
            }
        }

        if (preg_match(self::SYSLOG_RE, $line, $m)) {
            $year = (int) $this->reference->format('Y');
            $build = fn (int $y): \DateTimeImmutable|false => \DateTimeImmutable::createFromFormat(
                'Y M j H:i:s',
                sprintf('%d %s %d %s', $y, $m['mon'], (int) $m['day'], $m['time']),
                $this->timezone,
            );
            $t = $build($year);
            // Syslog omits the year. A date more than a day in the future
            // belongs to last year (a December log read in January).
            if (false !== $t && $t > $this->reference->modify('+1 day')) {
                $t = $build($year - 1);
            }

            return false === $t ? null : $t;
        }

        return null;
    }
}
