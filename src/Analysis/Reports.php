<?php

declare(strict_types=1);

namespace MailcowDeliverability\Analysis;

use MailcowDeliverability\Log\DeliveryEvent;

/**
 * The analyses behind each MCP tool, as plain functions over delivery events.
 * Every method returns a JSON-serialisable array; rendering happens elsewhere.
 */
final class Reports
{
    /** Below this many unique recipients a provider gets no verdict (TS: VERDICT_FLOOR / RECIPIENT_ALERT_FLOOR). */
    public const RECIPIENT_FLOOR = 20;

    /** RFC 3463 enhanced status codes seen most often in bounces. */
    public const DSN_MEANINGS = [
        '5.0.0' => 'Undefined permanent failure',
        '5.1.0' => 'Bad address (other)',
        '5.1.1' => 'Bad destination mailbox address',
        '5.1.2' => 'Bad destination system address',
        '5.1.10' => 'Recipient address has null MX',
        '5.2.0' => 'Mailbox status (other)',
        '5.2.1' => 'Mailbox disabled',
        '5.2.2' => 'Mailbox full',
        '5.3.4' => 'Message too big for system',
        '5.4.4' => 'Unable to route (no MX / host not found)',
        '5.4.7' => 'Delivery time expired',
        '5.5.0' => 'Protocol status (other)',
        '5.7.0' => 'Security or policy status (other)',
        '5.7.1' => 'Delivery not authorized, message refused',
        '5.7.23' => 'SPF validation failed',
        '5.7.25' => 'Reverse DNS validation failed',
        '5.7.26' => 'Multiple authentication checks failed',
        '5.7.27' => 'Sender address has null MX',
        '5.7.606' => 'Access denied, banned sending IP (Microsoft)',
        '4.4.7' => 'Delivery time expired (temporary)',
    ];

    /**
     * Per-provider delivery outcomes, alerts and a send recommendation.
     * Combines reputation_delivery_breakdown and reputation_warmup_status from the TS project.
     *
     * @param list<DeliveryEvent> $events
     * @param 'provider'|'domain'  $groupBy
     *
     * @return array<string, mixed>
     */
    public static function providerReputation(array $events, string $groupBy = 'provider', int $minRecipients = 1): array
    {
        $all = DeliveryAggregator::aggregate($events, $groupBy);
        $groups = array_values(array_filter($all, static fn (array $g): bool => $g['unique_recipients'] >= $minRecipients));

        $totals = ['sent' => 0, 'deferred' => 0, 'bounced' => 0, 'expired' => 0];
        $recipients = [];
        foreach ($events as $e) {
            if (isset($totals[$e->status])) {
                ++$totals[$e->status];
            }
            $recipients[$e->recipient] = true;
        }
        $attempts = \count($events);
        $unique = \count($recipients);

        $alerts = [];
        $rows = [];
        foreach ($groups as $g) {
            $failRate = DeliveryAggregator::pct($g['recipients_bounced'], $g['unique_recipients']);
            $stuckRate = DeliveryAggregator::pct($g['recipients_deferred'], $g['unique_recipients']);
            $critical = array_values(array_filter($g['top_reasons'], static fn (array $r): bool => 'critical' === $r['severity']));

            if ($g['unique_recipients'] >= self::RECIPIENT_FLOOR) {
                if ($failRate >= 2) {
                    $alerts[] = sprintf('%s: %d/%d recipients failed (%s%%).', $g['key'], $g['recipients_bounced'], $g['unique_recipients'], $failRate);
                }
                if ($stuckRate >= 5) {
                    $alerts[] = sprintf('%s: %d/%d recipients undelivered (%s%%).', $g['key'], $g['recipients_deferred'], $g['unique_recipients'], $stuckRate);
                }
            }
            // Critical responses are reported at any volume, always with the
            // recipient count so a two-address block is not read as an outage.
            foreach ($critical as $r) {
                $alerts[] = sprintf('%s (%d recipient%s): %s - "%s"', $g['key'], $g['unique_recipients'], 1 === $g['unique_recipients'] ? '' : 's', $r['category'], $r['sample']);
            }
            if ($g['attempts_per_recipient'] >= 10) {
                $alerts[] = sprintf('%s: retry storm - %d attempts for %d recipient(s) (%sx each). Suppress these addresses.', $g['key'], $g['total'], $g['unique_recipients'], $g['attempts_per_recipient']);
            }

            if ($g['unique_recipients'] < self::RECIPIENT_FLOOR) {
                $rec = 'insufficient_data';
            } elseif ([] !== $critical) {
                $rec = 'stop';
            } elseif ($failRate >= 2 || $stuckRate >= 10) {
                $rec = 'reduce';
            } elseif ($stuckRate >= 5) {
                $rec = 'hold';
            } elseif ($g['unique_recipients'] >= 50) {
                $rec = 'increase';
            } else {
                $rec = 'hold';
            }

            $rows[] = $g + ['failed_rate' => $failRate, 'undelivered_rate' => $stuckRate, 'recommendation' => $rec];
        }

        $scored = array_values(array_filter($rows, static fn (array $r): bool => 'insufficient_data' !== $r['recommendation']));
        $recs = array_column($scored, 'recommendation');
        $overall = match (true) {
            [] === $scored => 'insufficient_data',
            \in_array('stop', $recs, true) => 'stop',
            \in_array('reduce', $recs, true) => 'reduce',
            [] === array_diff($recs, ['increase']) => 'increase',
            default => 'hold',
        };

        $delivered = array_sum(array_column($groups, 'recipients_delivered'));
        $inGroups = array_sum(array_column($groups, 'unique_recipients'));

        return [
            'group_by' => $groupBy,
            'events_parsed' => $attempts,
            'unique_recipients' => $unique,
            'attempts_per_recipient' => $unique > 0 ? round($attempts / $unique, 1) : 0.0,
            'totals' => $totals + [
                'deferral_rate' => DeliveryAggregator::pct($totals['deferred'], $attempts),
                'bounce_rate' => DeliveryAggregator::pct($totals['bounced'] + $totals['expired'], $attempts),
                'recipients_delivered' => $delivered,
                'recipient_delivery_rate' => DeliveryAggregator::pct($delivered, $inGroups),
            ],
            'overall_recommendation' => $overall,
            'groups' => $rows,
            'alerts' => $alerts,
        ];
    }

    /**
     * Deferral reasons, classified and collapsed, most frequent first.
     *
     * @param list<DeliveryEvent> $events
     *
     * @return array<string, mixed>
     */
    public static function topDeferralReasons(array $events, ?string $provider = null, int $limit = 10): array
    {
        $deferred = array_values(array_filter(
            $events,
            static fn (DeliveryEvent $e): bool => 'deferred' === $e->status && self::matchesProvider($e, $provider),
        ));

        $buckets = [];
        $allRecipients = [];
        foreach ($deferred as $e) {
            $c = ReasonClassifier::classify($e->reason, $e->dsn);
            $sample = ReasonClassifier::normalize($e->reason);
            $key = $c['category'].'::'.$sample;
            $b = $buckets[$key] ?? [
                'category' => $c['category'],
                'severity' => $c['severity'],
                'remote_code' => ReasonClassifier::extractSmtpCode($e->reason),
                'dsn' => $e->dsn,
                'attempts' => 0,
                'recipients' => [],
                'providers' => [],
                'first_seen' => null,
                'last_seen' => null,
                'sample' => $sample,
            ];
            ++$b['attempts'];
            $b['recipients'][$e->recipient] = true;
            $b['providers'][$e->provider] = ($b['providers'][$e->provider] ?? 0) + 1;
            if (null !== $e->time) {
                $t = $e->time->format(\DATE_ATOM);
                $b['first_seen'] = null === $b['first_seen'] || $t < $b['first_seen'] ? $t : $b['first_seen'];
                $b['last_seen'] = null === $b['last_seen'] || $t > $b['last_seen'] ? $t : $b['last_seen'];
            }
            $buckets[$key] = $b;
            $allRecipients[$e->recipient] = true;
        }

        $reasons = [];
        foreach ($buckets as $b) {
            arsort($b['providers']);
            $b['unique_recipients'] = \count($b['recipients']);
            unset($b['recipients']);
            $reasons[] = $b;
        }
        usort($reasons, static fn (array $a, array $b): int => $b['attempts'] <=> $a['attempts'] ?: $b['unique_recipients'] <=> $a['unique_recipients']);

        $byCategory = [];
        foreach ($reasons as $r) {
            $byCategory[$r['category']] = ($byCategory[$r['category']] ?? 0) + $r['attempts'];
        }
        arsort($byCategory);

        return [
            'provider_filter' => $provider,
            'deferred_attempts' => \count($deferred),
            'deferred_recipients' => \count($allRecipients),
            'by_category' => $byCategory,
            'reasons' => \array_slice($reasons, 0, $limit),
        ];
    }

    /**
     * Hard failures grouped by Postfix DSN code.
     *
     * @param list<DeliveryEvent> $events
     *
     * @return array<string, mixed>
     */
    public static function bounceBreakdown(array $events, ?string $provider = null): array
    {
        $scoped = array_values(array_filter($events, static fn (DeliveryEvent $e): bool => self::matchesProvider($e, $provider)));
        $bounces = array_values(array_filter($scoped, static fn (DeliveryEvent $e): bool => $e->isHardFailure()));

        $codes = [];
        $recipients = [];
        foreach ($bounces as $e) {
            $c = ReasonClassifier::classify($e->reason, $e->dsn);
            $d = $codes[$e->dsn] ?? [
                'dsn' => $e->dsn,
                'meaning' => self::DSN_MEANINGS[$e->dsn] ?? self::DSN_MEANINGS[substr($e->dsn, 0, 3).'.0'] ?? 'Other',
                'count' => 0,
                'recipients' => [],
                'categories' => [],
                'providers' => [],
                'remote_codes' => [],
                'sample' => ReasonClassifier::normalize($e->reason),
            ];
            ++$d['count'];
            $d['recipients'][$e->recipient] = true;
            $d['categories'][$c['category']] = ($d['categories'][$c['category']] ?? 0) + 1;
            $d['providers'][$e->provider] = ($d['providers'][$e->provider] ?? 0) + 1;
            $rc = ReasonClassifier::extractSmtpCode($e->reason);
            if ('' !== $rc) {
                $d['remote_codes'][$rc] = ($d['remote_codes'][$rc] ?? 0) + 1;
            }
            $codes[$e->dsn] = $d;
            $recipients[$e->recipient] = true;
        }

        $rows = [];
        foreach ($codes as $d) {
            arsort($d['categories']);
            arsort($d['providers']);
            arsort($d['remote_codes']);
            $d['unique_recipients'] = \count($d['recipients']);
            unset($d['recipients']);
            $d['share'] = DeliveryAggregator::pct($d['count'], \count($bounces));
            $rows[] = $d;
        }
        usort($rows, static fn (array $a, array $b): int => $b['count'] <=> $a['count'] ?: strcmp($a['dsn'], $b['dsn']));

        $scopedRecipients = [];
        foreach ($scoped as $e) {
            $scopedRecipients[$e->recipient] = true;
        }

        return [
            'provider_filter' => $provider,
            'attempts_in_scope' => \count($scoped),
            'bounced_attempts' => \count($bounces),
            'bounced_recipients' => \count($recipients),
            'bounce_rate' => DeliveryAggregator::pct(\count($bounces), \count($scoped)),
            'recipient_bounce_rate' => DeliveryAggregator::pct(\count($recipients), \count($scopedRecipients)),
            'by_dsn' => $rows,
        ];
    }

    /**
     * Newest refusals first. A refusal is a bounce/expiry, or a deferral where the
     * remote said 5xx or named a blocklist (Postfix logs connect-stage 5xx
     * refusals as deferrals). include_temporary adds every other deferral.
     *
     * @param list<DeliveryEvent> $events
     *
     * @return array<string, mixed>
     */
    public static function recentRejections(array $events, int $limit = 20, bool $includeTemporary = false, ?string $provider = null): array
    {
        $rows = [];
        foreach ($events as $i => $e) {
            if ('sent' === $e->status || !self::matchesProvider($e, $provider)) {
                continue;
            }
            $c = ReasonClassifier::classify($e->reason, $e->dsn);
            $remote = ReasonClassifier::extractSmtpCode($e->reason);
            $refusal = $e->isHardFailure() || str_starts_with($remote, '5') || 'blocklisted' === $c['category'];
            if (!$refusal && !$includeTemporary) {
                continue;
            }
            $rows[] = [
                '_order' => $i,
                'time' => $e->time?->format(\DATE_ATOM),
                'queue_id' => $e->queueId,
                'recipient' => $e->recipient,
                'provider' => $e->provider,
                'status' => $e->status,
                'dsn' => $e->dsn,
                'remote_code' => $remote,
                'category' => $c['category'],
                'severity' => $c['severity'],
                'permanent' => $refusal,
                'relay' => $e->relay,
                'reason' => $e->reason,
            ];
        }

        $matched = \count($rows);
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $b['time'], (string) $a['time']) ?: $b['_order'] <=> $a['_order']);
        $rows = array_map(static function (array $r): array {
            unset($r['_order']);

            return $r;
        }, \array_slice($rows, 0, $limit));

        return [
            'provider_filter' => $provider,
            'include_temporary' => $includeTemporary,
            'matched' => $matched,
            'returned' => \count($rows),
            'rejections' => $rows,
        ];
    }

    private static function matchesProvider(DeliveryEvent $e, ?string $provider): bool
    {
        if (null === $provider || '' === $provider) {
            return true;
        }
        $p = strtolower($provider);

        return strtolower($e->provider) === $p || $e->recipientDomain === $p;
    }
}
