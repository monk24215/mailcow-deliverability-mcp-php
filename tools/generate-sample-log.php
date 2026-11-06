<?php

declare(strict_types=1);

/*
 * Generates the synthetic Postfix log in examples/logs/.
 *
 *   php tools/generate-sample-log.php
 *
 * Output is deterministic (fixed seed). Every address, domain and host is
 * under example.com / example.net / example.org, and every IP is from the
 * documentation ranges (192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24).
 * Response texts are modelled on what large mailbox providers send, with
 * hostnames, URLs and IDs replaced.
 *
 * Scenario: a newsletter sender on mail.example.org sends a daily issue to
 * about half its list for three days (2026-11-01 to 2026-11-03, UTC).
 *  - Google throttles with 421-4.7.28 at times; retries succeed.
 *  - Microsoft starts refusing part of the traffic on day 3 with S3150.
 *  - Yahoo/AOL defers heavily with TSS04; most retries succeed.
 *  - One small ISP rejects because the IP is on a DNS blocklist.
 *  - One unmapped domain refuses at connect time with an OFR code.
 *  - One address is deferred for the whole period (retry storm).
 *  - Company domains greylist the first attempt.
 *  - A few unknown users, full mailboxes, a dead domain, timeouts.
 */

mt_srand(20261103);

const OUR_IP = '192.0.2.10';
const START = 1793491200; // 2026-11-01T00:00:00Z
const END = START + 3 * 86400; // exclusive

$root = dirname(__DIR__);
$outDir = $root.'/examples/logs';
@mkdir($outDir, 0777, true);

// domain => [recipients, profile]
$domains = [
    'gmail.example.com' => [210, 'google'],
    'googlemail.example.com' => [14, 'google'],
    'outlook.example.com' => [80, 'microsoft'],
    'hotmail.example.com' => [56, 'microsoft'],
    'live.example.com' => [18, 'microsoft'],
    'yahoo.example.com' => [96, 'yahoo'],
    'aol.example.com' => [34, 'yahoo'],
    'icloud.example.com' => [44, 'apple'],
    'comcast.example.net' => [28, 'comcast'],
    'gmx.example.net' => [22, 'grey'],
    'acme.example.com' => [9, 'grey'],
    'globex.example.net' => [8, 'grey'],
    'initech.example.org' => [7, 'grey'],
    'smallisp.example.net' => [6, 'dnsbl'],
    'orange.example.org' => [5, 'ofr'],
    'nxdomain.example.net' => [3, 'nxdomain'],
    'refuser.example.net' => [1, 'storm'],
];

$relayIp = [];
$i = 20;
foreach (array_keys($domains) as $d) {
    $relayIp[$d] = sprintf('198.51.100.%d', $i++);
}

// Fixed per-recipient traits: a handful of dead or full mailboxes.
$recipients = [];
$n = 0;
foreach ($domains as $domain => [$count, $profile]) {
    for ($k = 0; $k < $count; ++$k) {
        ++$n;
        $trait = 'ok';
        $r = mt_rand(1, 1000);
        if ($r <= 18 && !in_array($profile, ['storm', 'nxdomain', 'ofr', 'dnsbl'], true)) {
            $trait = 'unknown';
        } elseif ($r <= 26 && in_array($profile, ['google', 'apple', 'yahoo'], true)) {
            $trait = 'full';
        }
        $recipients[] = ['addr' => sprintf('subscriber-%04d@%s', $n, $domain), 'domain' => $domain, 'profile' => $profile, 'trait' => $trait];
    }
}

$lines = []; // [unix, seq, text]
$seq = 0;
$usedQids = [];

function qid(): string
{
    global $usedQids;
    do {
        $q = strtoupper(rhex(5));
    } while (isset($usedQids[$q]));
    $usedQids[$q] = true;

    return $q;
}

function rhex(int $bytes): string
{
    $h = '';
    for ($i = 0; $i < $bytes; ++$i) {
        $h .= sprintf('%02x', mt_rand(0, 255));
    }

    return $h;
}

function ts(float $t): string
{
    $sec = (int) floor($t);
    $us = (int) (($t - $sec) * 1000000);

    return gmdate('Y-m-d\TH:i:s', $sec).sprintf('.%06d+00:00', $us);
}

function emit(float $t, string $prog, string $msg): void
{
    global $lines, $seq;
    $lines[] = [$t, $seq++, sprintf('%s mail %s[%d]: %s', ts($t), $prog, mt_rand(1000, 32000), $msg)];
}

function relay(string $domain): string
{
    global $relayIp;

    return sprintf('mx1.%s[%s]:25', $domain, $relayIp[$domain]);
}

/**
 * Outcome of one delivery attempt: [status, dsn, reason] (reason without host prefix handling).
 */
function attempt(array $rcpt, float $t, int $n, float $queuedAt): array
{
    $d = $rcpt['domain'];
    $host = sprintf('mx1.%s[%s]', $d, $GLOBALS['relayIp'][$d]);
    $day = (int) floor(($t - START) / 86400); // 0,1,2
    $hour = (int) gmdate('G', (int) $t);
    $r = mt_rand(1, 1000);

    // Recipient-level hard outcomes first.
    if ('unknown' === $rcpt['trait']) {
        return match ($rcpt['profile']) {
            'google' => ['bounced', '5.1.1', "host $host said: 550-5.1.1 The email account that you tried to reach does not exist. Please try double-checking the recipient's email address for typos or unnecessary spaces. 550 5.1.1 https://support.example.com/mail/?p=NoSuchUser x12si4410551qkb.88 - gsmtp (in reply to RCPT TO command)"],
            'microsoft' => ['bounced', '5.5.0', "host $host said: 550 5.5.0 Requested action not taken: mailbox unavailable (S2017062302). [DM6NAM10FT014.eop-nam10.prod.example.com] (in reply to RCPT TO command)"],
            'yahoo' => ['bounced', '5.0.0', "host $host said: 554 delivery error: dd This user doesn't have an account (subscriber) [0] - mta4012.mail.example.com (in reply to end of DATA command)"],
            default => ['bounced', '5.1.1', "host $host said: 550 5.1.1 <{$rcpt['addr']}>: Recipient address rejected: User unknown in virtual mailbox table (in reply to RCPT TO command)"],
        };
    }
    if ('full' === $rcpt['trait']) {
        if ('google' === $rcpt['profile']) {
            return $n < 4
                ? ['deferred', '4.2.2', "host $host said: 452-4.2.2 The recipient's inbox is out of storage space. Please direct the recipient to 452 4.2.2 https://support.example.com/mail/?p=OverQuotaTemp a1si551234qkb.12 - gsmtp (in reply to RCPT TO command)"]
                : ['bounced', '5.2.2', "host $host said: 552-5.2.2 The recipient's inbox is out of storage space and inactive. 552 5.2.2 https://support.example.com/mail/?p=OverQuotaPerm b2si661234qkb.7 - gsmtp (in reply to RCPT TO command)"];
        }

        return ['bounced', '5.2.2', "host $host said: 552 5.2.2 <{$rcpt['addr']}>: user is over quota (in reply to RCPT TO command)"];
    }

    // Occasional network trouble anywhere.
    if ($r <= 3 && 0 === $n) {
        return ['deferred', '4.4.1', sprintf('connect to %s:25: Connection timed out', $host)];
    }

    switch ($rcpt['profile']) {
        case 'google':
            // Throttling peaks on the afternoon of day 3.
            $p = (2 === $day && $hour >= 13 && $hour <= 17) ? 260 : 45;
            if (mt_rand(1, 1000) <= $p && $n < 3) {
                return ['deferred', '4.7.28', "host $host said: 421-4.7.28 [".OUR_IP."      15] Our system has detected an unusual rate of unsolicited mail originating from your IP address. To protect our users from spam, mail sent from your IP address has been temporarily rate limited. Please visit 421-4.7.28 https://support.example.com/mail/?p=UnsolicitedRateLimitError to review our Bulk Email Senders Guidelines. 421 4.7.28 c3si77123qkb.41 - gsmtp (in reply to end of DATA command)"];
            }

            return ['sent', '2.0.0', "250 2.0.0 OK  ".(int) $t.' d4si8812345qkb.'.mt_rand(10, 99).' - gsmtp'];

        case 'microsoft':
            if (2 === $day && $hour >= 9 && mt_rand(1, 100) <= 38) {
                return ['bounced', '5.7.1', "host $host said: 550 5.7.1 Unfortunately, messages from [".OUR_IP."] weren't sent. Please contact your Internet service provider since part of their network is on our block list (S3150). You can also refer your provider to https://postmaster.example.com/troubleshooting. [AM4PEPF00027A6C.eurprd04.prod.example.com 2026-11-03T".gmdate('H:i:s', (int) $t).'.000Z 08DE1A2B3C4D5E6F] (in reply to MAIL FROM command)'];
            }
            if (mt_rand(1, 1000) <= 70 && $n < 2) {
                return ['deferred', '4.7.650', "host $host said: 451 4.7.650 The mail server [".OUR_IP.'] has been temporarily rate limited due to IP reputation. For e-mail delivery information, see https://postmaster.example.com (S775) [Name=Protocol Filter Agent][AGT=PFA][MxId=11BB7E3A9C001D22] [BN8NAM12FT011.eop-nam12.prod.example.com] (in reply to MAIL FROM command)'];
            }

            return ['sent', '2.6.0', '250 2.6.0 <'.rhex(6).'@news.example.org> [InternalId=1'.mt_rand(10000000, 99999999).', Hostname=DM6PR11MB'.mt_rand(1000, 9999).'.namprd11.prod.example.com] Queued mail for delivery'];

        case 'yahoo':
            $p = 1 === $day ? 330 : 160;
            if (mt_rand(1, 1000) <= ($n < 2 ? $p : 120)) {
                return ['deferred', '4.7.0', "host $host said: 421 4.7.0 [TSS04] Messages from ".OUR_IP.' temporarily deferred due to unexpected volume or user complaints; see https://postmaster.example.com/error-codes (in reply to MAIL FROM command)'];
            }

            return ['sent', '2.0.0', '250 ok dirdel'];

        case 'apple':
            return ['sent', '2.0.0', '250 2.0.0 Ok: queued as '.strtoupper(rhex(4))];

        case 'comcast':
            if (mt_rand(1, 1000) <= 150 && $n < 2) {
                return ['deferred', '4.2.0', "host $host said: 451 4.2.0 Throttled - https://postmaster.example.net/smtp-error-codes.php#RL000010 (in reply to RCPT TO command)"];
            }

            return ['sent', '2.0.0', '250 2.0.0 '.rhex(4).' mail accepted for delivery'];

        case 'grey':
            if (0 === $n) {
                return ['deferred', '4.2.0', "host $host said: 450 4.2.0 <{$rcpt['addr']}>: Recipient address rejected: Greylisted, see https://postgrey.example.org/help/{$rcpt['domain']} (in reply to RCPT TO command)"];
            }

            return ['sent', '2.0.0', '250 2.0.0 Ok: queued as '.strtoupper(rhex(4))];

        case 'dnsbl':
            return ['bounced', '5.7.1', "host $host said: 554 5.7.1 Service unavailable; Client host [".OUR_IP.'] blocked using zen.dnsbl.example.org; https://dnsbl.example.org/query/ip/'.OUR_IP.' (in reply to RCPT TO command)'];

        case 'ofr':
            return ['deferred', '4.0.0', "host $host refused to talk to me: 550 mwinf5c42 ME Service refuse. Service refused. OFR006_103 [103]"];

        case 'nxdomain':
            return ['bounced', '5.4.4', "Host or domain name not found. Name service error for name={$rcpt['domain']} type=A: Host not found"];

        case 'storm':
            return ['deferred', '4.0.0', "host $host refused to talk to me: 550 [S10] Blocked"];
    }

    return ['sent', '2.0.0', '250 OK'];
}

// Daily send at 08:00 UTC, spread over ~2.5 hours, to about half the list.
for ($day = 0; $day < 3; ++$day) {
    $base = START + $day * 86400 + 8 * 3600;
    $batch = array_values(array_filter($recipients, static fn (array $r): bool => 'storm' === $r['profile'] ? 0 === $GLOBALS['day'] : mt_rand(1, 100) <= 52));
    // Day 3 send runs later, into the afternoon, so Google's throttle window is hit.
    $spread = 2 === $day ? 8 * 3600 : 9000;
    foreach ($batch as $rcpt) {
        $t = $base + mt_rand(0, $spread) + mt_rand(0, 999999) / 1e6;
        $q = qid();
        $size = mt_rand(38000, 61000);
        emit($t, 'postfix/submission/smtpd', 'connect from app.example.org[192.0.2.50]');
        emit($t + 0.05, 'postfix/submission/smtpd', "$q: client=app.example.org[192.0.2.50], sasl_method=PLAIN, sasl_username=newsletter@news.example.org");
        emit($t + 0.09, 'postfix/cleanup', "$q: message-id=<".rhex(8).'@news.example.org>');
        emit($t + 0.11, 'postfix/qmgr', "$q: from=<bounces+".mt_rand(100000, 999999)."@news.example.org>, size=$size, nrcpt=1 (queue active)");
        emit($t + 0.14, 'postfix/submission/smtpd', 'disconnect from app.example.org[192.0.2.50] ehlo=2 starttls=1 auth=1 mail=1 rcpt=1 data=1 quit=1 commands=8');

        $at = $t + 0.3 + mt_rand(1, 400) / 100;
        $backoff = 300;
        for ($n = 0; $at < END; ++$n) {
            [$status, $dsn, $reason] = attempt($rcpt, $at, $n, $t);
            $delay = round($at - $t, 2);
            $relay = str_starts_with($reason, 'connect to') || 'nxdomain' === $rcpt['profile'] ? 'none' : relay($rcpt['domain']);
            $d1 = round(min($delay, 0.12), 2);
            $d3 = mt_rand(5, 120) / 100;
            $d4 = mt_rand(1, 90) / 100;
            $d2 = max(0, round($delay - $d1 - $d3 - $d4, 2));
            emit($at, 'postfix/smtp', sprintf('%s: to=<%s>, relay=%s, delay=%s, delays=%s/%s/%s/%s, dsn=%s, status=%s (%s)',
                $q, $rcpt['addr'], $relay, $delay, $d1, $d2, $d3, $d4, $dsn, $status, $reason));
            if ('sent' === $status) {
                emit($at + 0.01, 'postfix/qmgr', "$q: removed");
                break;
            }
            if ('bounced' === $status) {
                $nq = qid();
                emit($at + 0.02, 'postfix/cleanup', "$nq: message-id=<".gmdate('YmdHis', (int) $at).".$nq@mail.example.org>");
                emit($at + 0.03, 'postfix/bounce', "$q: sender non-delivery notification: $nq");
                emit($at + 0.04, 'postfix/qmgr', "$q: removed");
                break;
            }
            // Postfix-style exponential backoff, capped at maximal_backoff_time (4000s).
            $at += $backoff + mt_rand(0, 120);
            $backoff = min($backoff * 2, 4000);
        }
    }
}

// Inbound noise on port 25: postscreen and a few rejected spam attempts.
for ($k = 0; $k < 320; ++$k) {
    $t = START + mt_rand(0, END - START - 1) + mt_rand(0, 999999) / 1e6;
    $ip = sprintf('203.0.113.%d', mt_rand(1, 254));
    emit($t, 'postfix/postscreen', "CONNECT from [$ip]:".mt_rand(30000, 65000).' to ['.OUR_IP.']:25');
    if (mt_rand(1, 3) === 1) {
        emit($t + 0.4, 'postfix/postscreen', "PREGREET 11 after 0.08 from [$ip]:".mt_rand(30000, 65000).': EHLO user\r\n');
        emit($t + 0.5, 'postfix/postscreen', "DISCONNECT [$ip]:".mt_rand(30000, 65000));
    } else {
        emit($t + 0.3, 'postfix/smtpd', "connect from unknown[$ip]");
        emit($t + 0.9, 'postfix/smtpd', "NOQUEUE: reject: RCPT from unknown[$ip]: 554 5.7.1 <info@mail.example.org>: Relay access denied; from=<spam".mt_rand(1, 999)."@sender.example.net> to=<info@mail.example.org> proto=ESMTP helo=<example.net>");
        emit($t + 1.1, 'postfix/smtpd', "disconnect from unknown[$ip] ehlo=1 mail=1 rcpt=0/1 quit=1 commands=3/4");
    }
}

usort($lines, static fn (array $a, array $b): int => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

$byDay = [[], [], []];
foreach ($lines as [$t, , $text]) {
    if ($t >= END) {
        continue;
    }
    $byDay[(int) floor(($t - START) / 86400)][] = $text;
}

foreach (glob($outDir.'/mail.log*') ?: [] as $old) {
    unlink($old);
}
file_put_contents($outDir.'/mail.log.2.gz', gzencode(implode("\n", $byDay[0])."\n", 9));
file_put_contents($outDir.'/mail.log.1', implode("\n", $byDay[1])."\n");
file_put_contents($outDir.'/mail.log', implode("\n", $byDay[2])."\n");

fprintf(STDERR, "Wrote %d lines: mail.log.2.gz %d, mail.log.1 %d, mail.log %d\n",
    count($byDay[0]) + count($byDay[1]) + count($byDay[2]), count($byDay[0]), count($byDay[1]), count($byDay[2]));
