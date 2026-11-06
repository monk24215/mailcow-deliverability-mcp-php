<?php

declare(strict_types=1);

namespace MailcowDeliverability\Tests\Unit;

use MailcowDeliverability\Analysis\ProviderMap;
use MailcowDeliverability\Log\PostfixLogParser;
use MailcowDeliverability\Tests\Fixtures;
use PHPUnit\Framework\TestCase;

final class PostfixLogParserTest extends TestCase
{
    private function parser(string $now = '2026-11-05T12:00:00+00:00'): PostfixLogParser
    {
        return new PostfixLogParser(new ProviderMap(), new \DateTimeZone('UTC'), new \DateTimeImmutable($now));
    }

    public function testParsesEveryDeliveryLine(): void
    {
        $events = $this->parser()->parseText(Fixtures::SAMPLE);
        self::assertCount(8, $events);
    }

    public function testExtractsFields(): void
    {
        $e = $this->parser()->parseText(Fixtures::SAMPLE)[1];
        self::assertSame('4A1B2C3D4F', $e->queueId);
        self::assertSame('test-recipient-bob@gmail.com', $e->recipient);
        self::assertSame('gmail.com', $e->recipientDomain);
        self::assertSame('Google', $e->provider);
        self::assertSame('deferred', $e->status);
        self::assertSame('4.7.0', $e->dsn);
        self::assertSame('gmail-smtp-in.example.com[192.0.2.26]:25', $e->relay);
        self::assertStringStartsWith('host gmail-smtp-in.example.com', $e->reason);
        self::assertStringEndsWith('(in reply to end of DATA command)', $e->reason);
    }

    public function testMapsProvidersAcrossDomains(): void
    {
        $providers = array_map(static fn ($e) => $e->provider, $this->parser()->parseText(Fixtures::SAMPLE));
        self::assertSame(['Google', 'Google', 'Yahoo/AOL', 'Microsoft', 'Microsoft', 'Comcast', 'Yahoo/AOL', 'Google'], $providers);
    }

    public function testIgnoresNonDeliveryLines(): void
    {
        $text = <<<'LOG'
2026-11-03T08:00:00.000000+00:00 mail postfix/qmgr[1]: 4A1B2C3D4E: from=<a@example.org>, size=100, nrcpt=1 (queue active)
2026-11-03T08:00:00.000000+00:00 mail postfix/smtpd[2]: NOQUEUE: reject: RCPT from unknown[203.0.113.9]: 554 5.7.1 <x@example.org>: Relay access denied; from=<s@example.net> to=<x@example.org> proto=ESMTP helo=<example.net>
2026-11-03T08:00:00.000000+00:00 mail postfix/qmgr[1]: 4A1B2C3D4E: removed
not a log line at all
LOG;
        self::assertSame([], $this->parser()->parseText($text));
    }

    public function testParsesRfc3339Timestamps(): void
    {
        $line = '2026-11-03T08:15:42.123456+01:00 mail postfix/smtp[5]: 0123456789AB: to=<a@example.com>, relay=mx.example.com[192.0.2.1]:25, delay=1, delays=0/0/0/1, dsn=2.0.0, status=sent (250 OK)';
        $e = $this->parser()->parseLine($line);
        self::assertNotNull($e);
        self::assertSame('2026-11-03T07:15:42+00:00', $e->time?->setTimezone(new \DateTimeZone('UTC'))->format(\DATE_ATOM));
    }

    public function testInfersYearForSyslogTimestamps(): void
    {
        $p = $this->parser('2026-11-05T12:00:00+00:00');
        self::assertSame('2026-08-25T09:12:01+00:00', $p->parseText(Fixtures::SAMPLE)[0]->time?->format(\DATE_ATOM));

        // A December line read in early January belongs to the previous year.
        $jan = $this->parser('2027-01-02T00:00:00+00:00');
        $e = $jan->parseLine('Dec 31 23:59:59 mail postfix/smtp[1]: 4A1B2C3D4E: to=<a@example.com>, relay=x[192.0.2.1]:25, delay=1, delays=0/0/0/1, dsn=2.0.0, status=sent (250 OK)');
        self::assertSame('2026-12-31T23:59:59+00:00', $e?->time?->format(\DATE_ATOM));
    }

    public function testAcceptsLongQueueIds(): void
    {
        $line = 'Nov  3 09:00:00 mail postfix/smtp[1]: 4XyZ1b2c3dz4j5: to=<a@example.com>, relay=mx.example.com[192.0.2.1]:25, delay=1, delays=0/0/0/1, dsn=2.0.0, status=sent (250 OK)';
        self::assertSame('4XyZ1b2c3dz4j5', $this->parser()->parseLine($line)?->queueId);
    }

    public function testUsesProviderMapOverrides(): void
    {
        $p = new PostfixLogParser(new ProviderMap(['gmail.example.com' => 'Google']));
        $e = $p->parseLine('Nov  3 09:00:00 mail postfix/smtp[1]: 4A1B2C3D4E: to=<a@GMAIL.example.com>, relay=x[192.0.2.1]:25, delay=1, delays=0/0/0/1, dsn=2.0.0, status=sent (250 OK)');
        self::assertSame('Google', $e?->provider);
        self::assertSame('a@gmail.example.com', $e?->recipient);
    }

    public function testUnknownStatusBecomesOther(): void
    {
        $e = $this->parser()->parseLine('Nov  3 09:00:00 mail postfix/smtp[1]: 4A1B2C3D4E: to=<a@example.com>, relay=x[192.0.2.1]:25, delay=1, delays=0/0/0/1, dsn=2.0.0, status=undeliverable (x)');
        self::assertSame('other', $e?->status);
    }
}
