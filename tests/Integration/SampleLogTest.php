<?php

declare(strict_types=1);

namespace MailcowDeliverability\Tests\Integration;

use MailcowDeliverability\Analysis\Reports;
use MailcowDeliverability\Config;
use MailcowDeliverability\DeliveryLog;
use PHPUnit\Framework\TestCase;

/**
 * Runs the analyses over the bundled sample log in examples/logs.
 */
final class SampleLogTest extends TestCase
{
    private static function log(): DeliveryLog
    {
        return DeliveryLog::fromConfig(Config::fromEnvironment([]));
    }

    public function testDefaultConfigUsesBundledSample(): void
    {
        $c = Config::fromEnvironment([]);
        self::assertTrue($c->usingSample);
        self::assertStringEndsWith('examples/logs', $c->logPath);
        self::assertStringEndsWith('examples/providers.sample.json', (string) $c->providerMapFile);

        $custom = Config::fromEnvironment(['MAILLOG_PATH' => '/var/log/mail.log']);
        self::assertFalse($custom->usingSample);
        self::assertNull($custom->providerMapFile);
    }

    public function testReadsAllRotatedFiles(): void
    {
        $info = self::log()->describe();
        self::assertSame('directory', $info['mode']);
        self::assertSame(['mail.log.2.gz', 'mail.log.1', 'mail.log'], array_map(static fn (array $f): string => basename($f['file']), $info['files']));
        self::assertGreaterThan(5000, $info['lines_read']);
        self::assertGreaterThan(1000, $info['delivery_events']);
        self::assertSame(0, $info['events_without_timestamp']);
        self::assertStringStartsWith('2026-11-01', (string) $info['first_event']);
        self::assertStringStartsWith('2026-11-03', (string) $info['last_event']);
    }

    public function testSampleContainsOnlyReservedNames(): void
    {
        foreach (self::log()->events() as $e) {
            self::assertMatchesRegularExpression('/\.example(\.(com|net|org))?$/', $e->recipientDomain);
        }
    }

    public function testLast24hShowsMicrosoftBlock(): void
    {
        $r = Reports::providerReputation(self::log()->window('24h')['events']);
        $groups = array_column($r['groups'], null, 'key');
        self::assertSame('stop', $groups['Microsoft']['recommendation']);
        self::assertSame('reputation_block', $groups['Microsoft']['top_reasons'][0]['category']);
        self::assertSame('stop', $r['overall_recommendation']);
        foreach (['Google', 'Yahoo/AOL', 'Apple'] as $p) {
            self::assertArrayHasKey($p, $groups);
        }
    }

    public function testSampleHasEachScenario(): void
    {
        $events = self::log()->window('all')['events'];
        $deferrals = Reports::topDeferralReasons($events, null, 100);
        self::assertArrayHasKey('greylisted', $deferrals['by_category']);
        self::assertArrayHasKey('throttled', $deferrals['by_category']);

        $bounces = array_column(Reports::bounceBreakdown($events)['by_dsn'], null, 'dsn');
        foreach (['5.7.1', '5.1.1', '5.2.2', '5.4.4'] as $dsn) {
            self::assertArrayHasKey($dsn, $bounces);
        }

        $byDomain = array_column(Reports::providerReputation($events, 'domain')['groups'], null, 'key');
        self::assertGreaterThanOrEqual(10, $byDomain['refuser.example.net']['attempts_per_recipient']);
        self::assertSame('blocklisted', $byDomain['smallisp.example.net']['top_reasons'][0]['category']);
    }
}
