<?php

declare(strict_types=1);

namespace MailcowDeliverability\Tests\Unit;

use MailcowDeliverability\Analysis\Reports;
use MailcowDeliverability\Log\DeliveryEvent;
use MailcowDeliverability\Log\PostfixLogParser;
use MailcowDeliverability\Tests\Fixtures;
use PHPUnit\Framework\TestCase;

final class ReportsTest extends TestCase
{
    /**
     * Builds $n recipients at $domain: $sent delivered, $bounced hard failures with $reason, rest deferred.
     *
     * @return list<DeliveryEvent>
     */
    private static function population(string $provider, int $n, int $bounced = 0, string $reason = '550 5.1.1 user unknown', int $deferred = 0): array
    {
        $events = [];
        $t = new \DateTimeImmutable('2026-11-03T10:00:00+00:00');
        for ($i = 0; $i < $n; ++$i) {
            [$status, $dsn, $why] = match (true) {
                $i < $bounced => ['bounced', '5.0.0', $reason],
                $i < $bounced + $deferred => ['deferred', '4.7.0', '421 4.7.0 temporarily deferred'],
                default => ['sent', '2.0.0', '250 OK'],
            };
            $events[] = new DeliveryEvent($t->modify("+$i seconds"), sprintf('Q%09d', $i), "r$i@$provider.example", "$provider.example", $provider, $status, $dsn, $why, 'mx.example[192.0.2.1]:25');
        }

        return $events;
    }

    private static function recommendationFor(array $report, string $key): string
    {
        return array_column($report['groups'], 'recommendation', 'key')[$key];
    }

    public function testRecommendations(): void
    {
        $events = array_merge(
            self::population('Clean', 60),
            self::population('Small', 19),
            self::population('Failing', 50, bounced: 2),
            self::population('Blocked', 30, bounced: 1, reason: '554 5.7.1 blocked using dnsbl.example'),
            self::population('Stuck', 40, deferred: 3),
            self::population('Modest', 30),
        );
        $r = Reports::providerReputation($events);

        self::assertSame('increase', self::recommendationFor($r, 'Clean'));
        self::assertSame('insufficient_data', self::recommendationFor($r, 'Small'));
        self::assertSame('reduce', self::recommendationFor($r, 'Failing'));
        self::assertSame('stop', self::recommendationFor($r, 'Blocked'));
        self::assertSame('hold', self::recommendationFor($r, 'Stuck'));
        self::assertSame('hold', self::recommendationFor($r, 'Modest'));
        self::assertSame('stop', $r['overall_recommendation']);
    }

    public function testOverallInsufficientWhenNothingScores(): void
    {
        $r = Reports::providerReputation((new PostfixLogParser())->parseText(Fixtures::SAMPLE));
        self::assertSame('insufficient_data', $r['overall_recommendation']);
        self::assertSame(8, $r['events_parsed']);
        // Critical findings are alerted at any volume.
        self::assertNotEmpty(array_filter($r['alerts'], static fn (string $a): bool => str_contains($a, 'blocklisted')));
    }

    public function testRetryStormAlert(): void
    {
        $r = Reports::providerReputation((new PostfixLogParser())->parseText(Fixtures::retryStorm()), 'domain');
        self::assertNotEmpty(array_filter($r['alerts'], static fn (string $a): bool => str_contains($a, 'refuser.example: retry storm')));
        self::assertSame(['yahoo.com', 'refuser.example'], array_column($r['groups'], 'key'));
    }

    public function testMinRecipientsFiltersGroups(): void
    {
        $r = Reports::providerReputation((new PostfixLogParser())->parseText(Fixtures::retryStorm()), 'domain', 2);
        self::assertSame(['yahoo.com'], array_column($r['groups'], 'key'));
    }

    public function testTopDeferralReasons(): void
    {
        $r = Reports::topDeferralReasons((new PostfixLogParser())->parseText(Fixtures::retryStorm()."\n".Fixtures::SAMPLE));
        self::assertSame(42, $r['deferred_attempts']);
        self::assertSame(3, $r['deferred_recipients']);
        $first = $r['reasons'][0];
        self::assertSame('reputation_block', $first['category']);
        self::assertSame(40, $first['attempts']);
        self::assertSame(1, $first['unique_recipients']);
        self::assertSame('550', $first['remote_code']);
        self::assertSame(['refuser.example' => 40], $first['providers']);

        $google = Reports::topDeferralReasons((new PostfixLogParser())->parseText(Fixtures::SAMPLE), 'google');
        self::assertSame(1, $google['deferred_attempts']);
        self::assertSame('throttled', $google['reasons'][0]['category']);
    }

    public function testBounceBreakdownGroupsByDsn(): void
    {
        $r = Reports::bounceBreakdown((new PostfixLogParser())->parseText(Fixtures::SAMPLE));
        self::assertSame(3, $r['bounced_attempts']);
        self::assertSame(37.5, $r['bounce_rate']);
        $byDsn = array_column($r['by_dsn'], null, 'dsn');
        self::assertSame(2, $byDsn['5.7.1']['count']);
        self::assertEqualsCanonicalizing(['blocklisted' => 1, 'reputation_block' => 1], $byDsn['5.7.1']['categories']);
        self::assertSame('Bad destination mailbox address', $byDsn['5.1.1']['meaning']);
        self::assertSame(['550' => 1], $byDsn['5.1.1']['remote_codes']);
        self::assertSame('5.7.1', $r['by_dsn'][0]['dsn']);

        $ms = Reports::bounceBreakdown((new PostfixLogParser())->parseText(Fixtures::SAMPLE), 'Microsoft');
        self::assertSame(1, $ms['bounced_attempts']);
        self::assertSame(50.0, $ms['bounce_rate']);
    }

    public function testRecentRejections(): void
    {
        $events = (new PostfixLogParser(reference: new \DateTimeImmutable('2026-11-05')))->parseText(Fixtures::SAMPLE."\n".Fixtures::retryStorm());
        $r = Reports::recentRejections($events, 5);
        // 3 bounces + 40 connect-stage 550 deferrals; the 421 Google throttle and the greylisting are excluded.
        self::assertSame(43, $r['matched']);
        self::assertSame(5, $r['returned']);
        self::assertSame('stuck@refuser.example', $r['rejections'][0]['recipient']);
        self::assertSame('2026-08-25T09:39:00+00:00', $r['rejections'][0]['time']);
        self::assertTrue($r['rejections'][0]['permanent']);

        $all = Reports::recentRejections($events, 100, includeTemporary: true);
        self::assertSame(45, $all['matched']);

        $bounces = Reports::recentRejections($events, 100, provider: 'Yahoo/AOL');
        self::assertSame(['test-recipient-gina@aol.com', 'test-recipient-carol@yahoo.com'], array_column($bounces['rejections'], 'recipient'));
    }
}
