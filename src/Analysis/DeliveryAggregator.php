<?php

declare(strict_types=1);

namespace MailcowDeliverability\Analysis;

use MailcowDeliverability\Log\DeliveryEvent;

/**
 * Groups delivery events by provider or recipient domain.
 *
 * Port of aggregateDeliveries() from the TS project. Each bucket carries two
 * denominators. Attempt counts (total/sent/deferred/bounced) describe MTA
 * traffic; recipient counts describe people. Postfix retries a deferred
 * address for days, so one refused address can produce 150+ attempts. Judge
 * reputation on the recipient fields and use attempts_per_recipient to spot
 * retry storms.
 */
final class DeliveryAggregator
{
    /**
     * @param list<DeliveryEvent> $events
     * @param 'provider'|'domain'  $groupBy
     *
     * @return list<array<string, mixed>> buckets sorted by unique recipients, descending
     */
    public static function aggregate(array $events, string $groupBy = 'provider', int $topReasons = 3): array
    {
        /** @var array<string, list<DeliveryEvent>> $groups */
        $groups = [];
        foreach ($events as $e) {
            $key = 'domain' === $groupBy ? $e->recipientDomain : $e->provider;
            $groups[$key][] = $e;
        }

        $buckets = [];
        foreach ($groups as $key => $list) {
            $counts = ['sent' => 0, 'deferred' => 0, 'bounced' => 0, 'expired' => 0];
            /** @var array<string, array{delivered: bool, hard: bool}> $byRecipient */
            $byRecipient = [];
            $reasons = [];

            foreach ($list as $e) {
                if (isset($counts[$e->status])) {
                    ++$counts[$e->status];
                }
                $rec = $byRecipient[$e->recipient] ?? ['delivered' => false, 'hard' => false];
                if ('sent' === $e->status) {
                    $rec['delivered'] = true;
                }
                if ($e->isHardFailure()) {
                    $rec['hard'] = true;
                }
                $byRecipient[$e->recipient] = $rec;

                if ('sent' === $e->status || '' === $e->reason) {
                    continue;
                }
                $c = ReasonClassifier::classify($e->reason, $e->dsn);
                $sample = ReasonClassifier::normalize($e->reason);
                $rk = $c['category'].'::'.$sample;
                if (isset($reasons[$rk])) {
                    ++$reasons[$rk]['count'];
                } else {
                    $reasons[$rk] = ['category' => $c['category'], 'severity' => $c['severity'], 'count' => 1, 'sample' => $sample];
                }
            }

            $total = \count($list);
            $unique = \count($byRecipient);
            // The outcome that counts per recipient is the best one they got:
            // a success anywhere means the message reached them.
            $delivered = 0;
            $bounced = 0;
            foreach ($byRecipient as $rec) {
                if ($rec['delivered']) {
                    ++$delivered;
                } elseif ($rec['hard']) {
                    ++$bounced;
                }
            }

            $reasons = array_values($reasons);
            usort($reasons, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

            $buckets[] = [
                'key' => (string) $key,
                'total' => $total,
                'sent' => $counts['sent'],
                'deferred' => $counts['deferred'],
                'bounced' => $counts['bounced'],
                'expired' => $counts['expired'],
                'deferral_rate' => self::pct($counts['deferred'], $total),
                'bounce_rate' => self::pct($counts['bounced'] + $counts['expired'], $total),
                'unique_recipients' => $unique,
                'recipients_delivered' => $delivered,
                'recipients_bounced' => $bounced,
                'recipients_deferred' => $unique - $delivered - $bounced,
                'delivery_rate' => self::pct($delivered, $unique),
                'attempts_per_recipient' => $unique > 0 ? round($total / $unique, 1) : 0.0,
                'top_reasons' => \array_slice($reasons, 0, $topReasons),
            ];
        }

        // Sort by people reached, not packets sent.
        usort($buckets, static fn (array $a, array $b): int => $b['unique_recipients'] <=> $a['unique_recipients'] ?: strcmp($a['key'], $b['key']));

        return $buckets;
    }

    public static function pct(int|float $part, int|float $whole): float
    {
        return $whole > 0 ? round($part / $whole * 100, 2) : 0.0;
    }
}
