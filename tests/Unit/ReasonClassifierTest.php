<?php

declare(strict_types=1);

namespace MailcowDeliverability\Tests\Unit;

use MailcowDeliverability\Analysis\ReasonClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReasonClassifierTest extends TestCase
{
    /**
     * Cases from scripts/parser-test.mjs in the TypeScript project, including its
     * "real world" rejection strings (hosts, addresses and URLs replaced).
     *
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function cases(): iterable
    {
        yield '4.x throttle is not a hard block' => ['421-4.7.28 Our system has detected an unusual rate of unsolicited mail', '4.7.0', 'throttled', 'warning'];
        yield 'blocklist outranks everything' => ['this IP is listed by Spamhaus', '5.7.1', 'blocklisted', 'critical'];
        yield '5.x policy refusal is a reputation block' => ['554 5.7.9 Message not accepted for policy reasons', '5.7.1', 'reputation_block', 'critical'];
        yield 'user unknown' => ['550 5.1.1 mailbox unavailable', '5.1.1', 'user_unknown', 'info'];
        yield 'greylisting beats generic throttling' => ['421 4.2.0 Greylisted, please try again later', '4.2.0', 'greylisted', 'info'];
        yield 'auth failure' => ['550 5.7.26 This message does not pass authentication checks (SPF and DKIM)', '5.7.26', 'auth_failure', 'critical'];
        yield 'blacklisted (inflected) is a blocklist hit' => ['host mx.isp.example[192.0.2.1] refused to talk to me: 521 5.5.0 Your IP [192.0.2.10] has been blacklisted. Please contact abuse@isp.example for more information.', '4.0.0', 'blocklisted', 'critical'];
        yield 'terse [S10] Blocked' => ['host mx1.relay.example[192.0.2.1] refused to talk to me: 550 [S10] Blocked', '4.0.0', 'reputation_block', 'critical'];
        yield 'remote 554 beats the Postfix 4.x DSN' => ["host mx2.gateway.example[192.0.2.1] refused to talk to me: 554-esa6.gateway.example 554 Your access to this mail system has been rejected due to the sending MTA's poor reputation.", '4.0.0', 'reputation_block', 'critical'];
        yield 'OFR code' => ['host smtp-in.isp.example[192.0.2.1] refused to talk to me: 550 opmta1mti37nd1 smtp.isp.example xZBIwcGuCj51b Service refuse. Service refused. OFR006_103 - please visit: https://postmaster.isp.example/ [103]', '4.0.0', 'reputation_block', 'critical'];
        yield 'new-IP throttle stays a throttle' => ['host mx2.hosting.example[192.0.2.1] said: 451 4.7.1 Received too many messages from a new or untrusted IP: 192.0.2.10 (Z27/80343E7) (G28) (in reply to RCPT TO command)', '4.7.1', 'throttled', 'warning'];
        yield 'over quota is not reputation' => ["host alt1.mx.example[192.0.2.1] said: 452-4.2.2 The recipient's inbox is out of storage space.", '4.2.2', 'mailbox_full', 'info'];
        yield 'Throttled stays a throttle' => ['host mx2.cable.example[192.0.2.1] said: 451 4.2.0 Throttled - https://postmaster.cable.example/smtp-error-codes.php#RL000010', '4.2.0', 'throttled', 'warning'];
        yield 'address does not exist is list hygiene' => ['host route3.mx.example[192.0.2.1] said: 550 5.1.1 Address does not exist. H471xiwLArEQ', '5.1.1', 'user_unknown', 'info'];
        // Added in this port: a common "no such account" wording the TS table classified as "other".
        yield 'no account wording is user unknown' => ["host mx.example[192.0.2.1] said: 554 delivery error: dd This user doesn't have a yahoo.com account (x) [0]", '5.0.0', 'user_unknown', 'info'];
        yield 'IP reputation with 4xx is a throttle' => ['451 4.7.650 The mail server [192.0.2.10] has been temporarily rate limited due to IP reputation.', '4.7.650', 'throttled', 'warning'];
        yield 'block list S3150 with 5xx is a reputation block' => ["550 5.7.1 Unfortunately, messages from [192.0.2.10] weren't sent. Please contact your Internet service provider since part of their network is on our block list (S3150).", '5.7.1', 'reputation_block', 'critical'];
        yield 'dnsbl name is a blocklist hit' => ['554 5.7.1 Service unavailable; Client host [192.0.2.10] blocked using zen.dnsbl.example.org', '5.7.1', 'blocklisted', 'critical'];
        yield 'dead domain' => ['Host or domain name not found. Name service error for name=nx.example type=A: Host not found', '5.4.4', 'domain_error', 'info'];
        yield 'connection timeout' => ['connect to mx.example[192.0.2.1]:25: Connection timed out', '4.4.1', 'connection_error', 'warning'];
        yield 'unmatched text' => ['something unexpected happened', '5.0.0', 'other', 'info'];
    }

    #[DataProvider('cases')]
    public function testClassify(string $reason, string $dsn, string $category, string $severity): void
    {
        self::assertSame(['category' => $category, 'severity' => $severity], ReasonClassifier::classify($reason, $dsn));
    }

    public function testExtractSmtpCode(): void
    {
        self::assertSame('554', ReasonClassifier::extractSmtpCode('refused to talk to me: 554-esa6 554 poor reputation'));
        self::assertSame('421', ReasonClassifier::extractSmtpCode('said: 421-4.7.28 Our system'));
        self::assertSame('', ReasonClassifier::extractSmtpCode('connect to mx.example[192.0.2.1]:25: Connection timed out'));
    }

    public function testNormalizeCollapsesVolatileParts(): void
    {
        $a = ReasonClassifier::normalize('550 5.7.1 messages from [192.0.2.10] (S3150) [AM4PEPF00027A6C.prod.example.com]');
        $b = ReasonClassifier::normalize('550 5.7.1 messages from [192.0.2.11] (S3150) [BN8NAM12FT011XY.prod.example.com]');
        self::assertSame($a, $b);
        self::assertStringContainsString('<ip>', $a);
        self::assertLessThanOrEqual(220, mb_strlen(ReasonClassifier::normalize(str_repeat('word ', 200))));
    }
}
