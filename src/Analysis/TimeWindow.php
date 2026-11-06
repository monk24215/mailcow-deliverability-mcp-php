<?php

declare(strict_types=1);

namespace MailcowDeliverability\Analysis;

use MailcowDeliverability\Log\DeliveryEvent;

/**
 * A look-back window such as "24h", "7d", "90m" or "all".
 *
 * The window ends at an anchor: by default the newest timestamp in the log
 * ("latest"), so an archived or sample log still gives a meaningful "last 24h".
 * Pass anchor "now" to measure against the wall clock instead.
 */
final class TimeWindow
{
    private function __construct(
        public readonly string $spec,
        public readonly ?int $seconds,
    ) {
    }

    public static function parse(string $spec): self
    {
        $s = strtolower(trim($spec));
        if ('' === $s || 'all' === $s) {
            return new self('all', null);
        }
        if (!preg_match('/^(\d+)\s*(m|min|h|d|w)$/', $s, $m)) {
            throw new \InvalidArgumentException(sprintf('Invalid window "%s". Use a number with m, h, d or w (e.g. "90m", "24h", "7d"), or "all".', $spec));
        }
        $mult = match ($m[2]) {
            'm', 'min' => 60,
            'h' => 3600,
            'd' => 86400,
            'w' => 604800,
        };

        return new self($m[1].('min' === $m[2] ? 'm' : $m[2]), (int) $m[1] * $mult);
    }

    /**
     * @param list<DeliveryEvent> $events
     *
     * @return array{events: list<DeliveryEvent>, from: ?\DateTimeImmutable, to: ?\DateTimeImmutable}
     */
    public function apply(array $events, string $anchor = 'latest', ?\DateTimeImmutable $now = null): array
    {
        $latest = null;
        foreach ($events as $e) {
            if (null !== $e->time && (null === $latest || $e->time > $latest)) {
                $latest = $e->time;
            }
        }
        $end = 'now' === $anchor ? ($now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) : $latest;

        if (null === $this->seconds) {
            return ['events' => $events, 'from' => null, 'to' => $end];
        }
        if (null === $end) {
            return ['events' => [], 'from' => null, 'to' => null];
        }

        $from = $end->modify(sprintf('-%d seconds', $this->seconds));
        $kept = array_values(array_filter(
            $events,
            static fn (DeliveryEvent $e): bool => null !== $e->time && $e->time > $from && $e->time <= $end,
        ));

        return ['events' => $kept, 'from' => $from, 'to' => $end];
    }
}
