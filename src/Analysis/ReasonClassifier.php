<?php

declare(strict_types=1);

namespace MailcowDeliverability\Analysis;

/**
 * Classifies a remote MTA response into a reputation-relevant category.
 *
 * Port of classifyReason()/REASON_PATTERNS from the TypeScript project. Rules:
 *  - A blocklist mention outranks everything, at any SMTP class.
 *  - The remote server's own SMTP reply code decides permanence; Postfix's DSN
 *    is only a fallback. Postfix logs a connect-stage "554 ... poor reputation"
 *    refusal as a deferral with a 4.x.x DSN.
 *  - A 4.x.x response is never a permanent reputation block; it is a throttle.
 *  - For 4.x.x responses, greylisting / throttling / connection errors are
 *    tested before the rest of the table.
 */
final class ReasonClassifier
{
    public const SEVERITY_RANK = ['critical' => 3, 'warning' => 2, 'info' => 1];

    /**
     * Ordered table: category => [severity, pattern]. First match wins.
     *
     * Every alternative carries its own word boundaries. Wrapping the whole
     * alternation in \b(...)\b makes stems like "throttl" or "blacklist" miss
     * the inflected forms servers send ("Throttled", "has been blacklisted").
     */
    public const PATTERNS = [
        'blocklisted' => ['critical', '/(\bspamhaus\b|\bbarracudacentral\b|\bblocked using\b|\blisted by\b|\blisted in\b|\bdnsbl\b|\brbl\b|\bsbl-xbl\b|\buceprotect\b|\bspamcop\b|\bsenderscore\b|\binvaluement\b|\bsorbs\b|\bpsbl\b|\bspamrats\b|\bblack-?list(ed|ing)?\b|\bdeny-?list(ed|ing)?\b|\bblock-?list(ed|ing)?\b)/i'],
        'reputation_block' => ['critical', '/(\bunsolicited\b|\bbulk mail\b|\bspam-?like\b|\bspammy\b|\bpoor reputation\b|\bbad reputation\b|\bip reputation\b|\bsender reputation\b|\bnot accepted for policy reasons\b|\bs3150\b|\[s\d{1,3}\]\s*blocked\b|\bservice refus(e|ed|é)\b|\bofr\d{3}_\d+\b|\baccess to this mail system has been rejected\b|\bhas been blocked\b|\bwe do not accept mail\b|\b5\.7\.606\b|\b5\.7\.1 service unavailable\b|\bblocked\b)/iu'],
        'throttled' => ['warning', '/(\brate ?limit\w*|\btoo many\b|\bthrottl\w*|\btry again later\b|\btemporarily deferred\b|\btemporarily rate limited\b|\b4\.7\.28\b|\b4\.7\.0 \[ts0|\b421 4\.7|\bslow down\b|\bconnection limit\w*\b|\btoo much mail\b|\bdeferred due to (?:user|excessive) complaints\b|\btoo fast\b|\bnew or untrusted ip\b|\bsending rate\b)/i'],
        'auth_failure' => ['critical', '/(\bspf\b|\bdkim\b|\bdmarc\b|\bdnssec\b|does not (?:pass|meet) .*(?:authentication|spf|dkim)|\bunauthenticated\b|\bauthentication check\w*|\b5\.7\.23\b|\b5\.7\.26\b|\b5\.7\.20\b|\b5\.7\.509\b)/i'],
        'greylisted' => ['info', '/(\bgrey-?list\w*|\bgray-?list\w*|\b4\.7\.1 greylisting\b|\btry again in\b|\b450 4\.2\.0\b)/i'],
        'user_unknown' => ['info', '/(\buser unknown\b|\bdoesn\'t have an? (?:[\w.-]+ )?account\b|\bno such user\b|recipient (?:address )?rejected|\bunknown user\b|does not exist|\bmailbox unavailable\b|address (?:not found|rejected)|\b5\.1\.1\b|\b5\.1\.10\b|\b550 5\.1)/i'],
        'mailbox_full' => ['info', '/(\bover ?quota\b|\bquota exceeded\b|\bmailbox full\b|insufficient (?:system )?storage|out of storage space|\b4\.2\.2\b|\b5\.2\.2\b|\b4\.1\.1 <)/i'],
        'domain_error' => ['info', '/(host or domain name not found|name service error|\bno mx\b|unable to look up|domain not found|\bhost not found\b|\b4\.4\.3\b|\b5\.4\.4\b)/i'],
        'connection_error' => ['warning', '/(connection timed out|connection refused|lost connection|network is unreachable|no route to host|\btimed? ?out\b|conversation with .* timed out)/i'],
        'tls_error' => ['warning', '/(\btls\b|\bcertificate\b|\bssl\b|\bhandshake\b)/i'],
    ];

    /** Tested first when the response is temporary (4.x.x), in this order. */
    private const TEMP_PRIORITY = ['greylisted', 'throttled', 'connection_error'];

    /**
     * The remote MTA's own three-digit SMTP reply code, or '' when it gives none.
     */
    public static function extractSmtpCode(string $reason): string
    {
        return preg_match('/(?:^|[\s:])([45]\d{2})(?:[\s-]|$)/', $reason, $m) ? $m[1] : '';
    }

    /**
     * @return array{category: string, severity: string}
     */
    public static function classify(string $reason, string $dsn = ''): array
    {
        $remoteCode = self::extractSmtpCode($reason);
        // The remote's own code wins; the Postfix DSN is only a fallback.
        $effective = '' !== $remoteCode ? $remoteCode[0].'.0.0' : $dsn;
        $temporary = str_starts_with($effective, '4');

        [$sev, $pattern] = self::PATTERNS['blocklisted'];
        if (preg_match($pattern, $reason)) {
            return ['category' => 'blocklisted', 'severity' => $sev];
        }

        $order = array_keys(self::PATTERNS);
        if ($temporary) {
            $order = array_merge(self::TEMP_PRIORITY, array_values(array_diff($order, self::TEMP_PRIORITY)));
        }

        foreach ($order as $category) {
            if ('blocklisted' === $category) {
                continue;
            }
            [$severity, $pattern] = self::PATTERNS[$category];
            if (preg_match($pattern, $reason)) {
                // A temporary response never counts as a permanent reputation block.
                if ('reputation_block' === $category && $temporary) {
                    return ['category' => 'throttled', 'severity' => 'warning'];
                }

                return ['category' => $category, 'severity' => $severity];
            }
        }

        return ['category' => 'other', 'severity' => 'info'];
    }

    /**
     * Strip volatile identifiers so similar response texts collapse into one bucket.
     */
    public static function normalize(string $reason): string
    {
        $s = preg_replace('/\b\d{1,3}(?:\.\d{1,3}){3}\b/', '<ip>', $reason) ?? $reason;
        $s = preg_replace('/\b[0-9a-f]{16,}\b/i', '<id>', $s) ?? $s;
        $s = preg_replace('/\b[A-Z0-9]{10,}\b/', '<id>', $s) ?? $s;
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;

        return mb_substr(trim($s), 0, 220);
    }
}
