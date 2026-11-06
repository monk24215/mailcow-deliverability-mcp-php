<?php

declare(strict_types=1);

namespace MailcowDeliverability\Tests;

/**
 * Log lines used across tests. Adapted from scripts/parser-test.mjs in the
 * TypeScript project, with hosts and addresses moved to example.com-style
 * names and the provider domains kept so the built-in map applies.
 */
final class Fixtures
{
    public const SAMPLE = <<<'LOG'
Aug 25 09:12:01 mail postfix/smtp[2211]: 4A1B2C3D4E: to=<test-recipient-alice@gmail.com>, relay=gmail-smtp-in.example.com[192.0.2.26]:25, delay=1.4, delays=0.1/0/0.6/0.7, dsn=2.0.0, status=sent (250 2.0.0 OK  1724577121 x12-20020a05 - gsmtp)
Aug 25 09:12:03 mail postfix/smtp[2212]: 4A1B2C3D4F: to=<test-recipient-bob@gmail.com>, relay=gmail-smtp-in.example.com[192.0.2.26]:25, delay=2.1, delays=0.1/0/1.2/0.8, dsn=4.7.0, status=deferred (host gmail-smtp-in.example.com[192.0.2.26] said: 421-4.7.28 Our system has detected an unusual rate of unsolicited mail originating from your IP address (in reply to end of DATA command))
Aug 25 09:12:05 mail postfix/smtp[2213]: 4A1B2C3D50: to=<test-recipient-carol@yahoo.com>, relay=mta5.example.net[198.51.100.72]:25, delay=3.0, delays=0.1/0/1.0/1.9, dsn=5.7.1, status=bounced (host mta5.example.net[198.51.100.72] said: 554 5.7.9 Message not accepted for policy reasons. See https://senders.example.com/ (in reply to end of DATA command))
Aug 25 09:12:07 mail postfix/smtp[2214]: 4A1B2C3D51: to=<test-recipient-dave@hotmail.com>, relay=hotmail-com.example.com[198.51.100.33]:25, delay=4.2, delays=0.1/0/2.0/2.1, dsn=5.1.1, status=bounced (host hotmail-com.example.com said: 550 5.1.1 Requested action not taken: mailbox unavailable)
Aug 25 09:12:09 mail postfix/smtp[2215]: 4A1B2C3D52: to=<test-recipient-erin@hotmail.com>, relay=hotmail-com.example.com[198.51.100.33]:25, delay=1.0, delays=0.1/0/0.4/0.5, dsn=2.0.0, status=sent (250 2.6.0 <id> Queued mail for delivery)
Aug 25 09:12:11 mail postfix/smtp[2216]: 4A1B2C3D53: to=<test-recipient-frank@comcast.net>, relay=mx1.example.net[198.51.100.80]:25, delay=6.0, delays=0.1/0/3.0/2.9, dsn=4.2.0, status=deferred (host mx1.example.net said: 421 4.2.0 Greylisted, please try again later)
Aug 25 09:12:13 mail postfix/smtp[2217]: 4A1B2C3D54: to=<test-recipient-gina@aol.com>, relay=mx-aol.example.net[198.51.100.63]:25, delay=2.2, delays=0.1/0/1.0/1.1, dsn=5.7.1, status=bounced (host mx-aol.example.net said: 553 5.7.1 [BL21] Connections will not be accepted from 192.0.2.4, because this IP is listed by Spamhaus)
Aug 25 09:12:15 mail postfix/smtp[2218]: 4A1B2C3D55: to=<test-recipient-hank@gmail.com>, relay=gmail-smtp-in.example.com[192.0.2.26]:25, delay=1.1, delays=0.1/0/0.5/0.5, dsn=2.0.0, status=sent (250 2.0.0 OK)
LOG;

    /**
     * One address deferred 40 times beside 30 delivered recipients.
     */
    public static function retryStorm(): string
    {
        $qid = static fn (string $prefix, int $i): string => $prefix.strtoupper(str_pad(dechex($i), 2, '0', \STR_PAD_LEFT));
        $lines = [];
        for ($i = 0; $i < 40; ++$i) {
            $lines[] = sprintf('Aug 25 09:%02d:00 mail postfix/smtp[9%d]: %s: to=<stuck@refuser.example>, relay=mx.refuser.example[192.0.2.1]:25, delay=1, delays=0/0/0/1, dsn=4.0.0, status=deferred (host mx.refuser.example refused to talk to me: 550 [S10] Blocked)', $i % 60, $i, $qid('4A1B2C3D', $i));
        }
        for ($i = 0; $i < 30; ++$i) {
            $lines[] = sprintf('Aug 25 10:%02d:00 mail postfix/smtp[8%d]: %s: to=<test-user-%d@yahoo.com>, relay=mta5.example.net[198.51.100.72]:25, delay=1, delays=0/0/0/1, dsn=2.0.0, status=sent (250 ok)', $i % 60, $i, $qid('5B2C3D4E', $i), $i);
        }

        return implode("\n", $lines);
    }
}
