<?php

declare(strict_types=1);

namespace MailcowDeliverability\Tests\Unit;

use MailcowDeliverability\Analysis\DeliveryAggregator;
use MailcowDeliverability\Log\PostfixLogParser;
use MailcowDeliverability\Tests\Fixtures;
use PHPUnit\Framework\TestCase;

final class DeliveryAggregatorTest extends TestCase
{
    /**
     * @return array<string, array<string, mixed>>
     */
    private static function byKey(array $buckets): array
    {
        return array_column($buckets, null, 'key');
    }

    public function testGroupsByProvider(): void
    {
        $b = self::byKey(DeliveryAggregator::aggregate((new PostfixLogParser())->parseText(Fixtures::SAMPLE)));

        self::assertSame([3, 2, 1, 0], [$b['Google']['total'], $b['Google']['sent'], $b['Google']['deferred'], $b['Google']['bounced']]);
        self::assertSame([2, 2], [$b['Yahoo/AOL']['total'], $b['Yahoo/AOL']['bounced']]);
        self::assertSame([2, 1], [$b['Microsoft']['total'], $b['Microsoft']['sent']]);
        self::assertSame(1, $b['Comcast']['deferred']);
        self::assertSame(33.33, $b['Google']['deferral_rate']);
        self::assertSame(100.0, $b['Yahoo/AOL']['bounce_rate']);
    }

    public function testTopReasonsAreClassified(): void
    {
        $b = self::byKey(DeliveryAggregator::aggregate((new PostfixLogParser())->parseText(Fixtures::SAMPLE)));

        self::assertSame('throttled', $b['Google']['top_reasons'][0]['category']);
        $yahoo = array_column($b['Yahoo/AOL']['top_reasons'], 'severity', 'category');
        self::assertSame('critical', $yahoo['blocklisted'] ?? null);
        self::assertSame('critical', $yahoo['reputation_block'] ?? null);
    }

    public function testGroupsByDomain(): void
    {
        $b = self::byKey(DeliveryAggregator::aggregate((new PostfixLogParser())->parseText(Fixtures::SAMPLE), 'domain'));
        self::assertArrayHasKey('aol.com', $b);
        self::assertArrayHasKey('yahoo.com', $b);
        self::assertSame(1, $b['aol.com']['total']);
    }

    public function testRetryStormCollapsesToRecipients(): void
    {
        $buckets = DeliveryAggregator::aggregate((new PostfixLogParser())->parseText(Fixtures::retryStorm()), 'domain');
        $b = self::byKey($buckets);
        $refuser = $b['refuser.example'];
        $yahoo = $b['yahoo.com'];

        self::assertSame(1, $refuser['unique_recipients']);
        self::assertSame(40, $refuser['total']);
        self::assertGreaterThanOrEqual(10, $refuser['attempts_per_recipient']);
        self::assertSame(1, $refuser['recipients_deferred']);
        self::assertSame(0, $refuser['recipients_delivered']);
        self::assertSame(30, $yahoo['unique_recipients']);
        self::assertSame(100.0, $yahoo['delivery_rate']);
        // Sorted by people, not attempts.
        self::assertSame('yahoo.com', $buckets[0]['key']);
    }

    public function testRecipientDeliveredAfterDeferralCountsAsDelivered(): void
    {
        $log = <<<'LOG'
Nov  3 09:00:00 mail postfix/smtp[1]: 4A1B2C3D4E: to=<a@gmail.com>, relay=x[192.0.2.1]:25, delay=1, delays=0/0/0/1, dsn=4.7.28, status=deferred (said: 421-4.7.28 rate limited)
Nov  3 09:10:00 mail postfix/smtp[1]: 4A1B2C3D4E: to=<a@gmail.com>, relay=x[192.0.2.1]:25, delay=601, delays=0/600/0/1, dsn=2.0.0, status=sent (250 OK)
Nov  3 09:00:00 mail postfix/smtp[1]: 4A1B2C3D4F: to=<b@gmail.com>, relay=x[192.0.2.1]:25, delay=1, delays=0/0/0/1, dsn=5.1.1, status=bounced (said: 550 5.1.1 user unknown)
LOG;
        $g = DeliveryAggregator::aggregate((new PostfixLogParser())->parseText($log))[0];
        self::assertSame(['unique_recipients' => 2, 'recipients_delivered' => 1, 'recipients_bounced' => 1, 'recipients_deferred' => 0, 'delivery_rate' => 50.0],
            array_intersect_key($g, array_flip(['unique_recipients', 'recipients_delivered', 'recipients_bounced', 'recipients_deferred', 'delivery_rate'])));
    }
}
