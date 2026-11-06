<?php

declare(strict_types=1);

namespace MailcowDeliverability\Mcp;

/**
 * Renders tool results as short Markdown for the text content of a tool result.
 * The structured content carries the complete data.
 */
final class Markdown
{
    private const LIMIT = 25000;

    /**
     * @param array<string, mixed> $d
     */
    public static function providerReputation(array $d): string
    {
        $out = [self::heading('Provider reputation', $d['window'])];
        if (0 === $d['events_parsed']) {
            $out[] = 'No delivery events in this window. Try a longer window ("7d" or "all"), or check log_source_info.';

            return implode("\n", $out);
        }
        $t = $d['totals'];
        $out[] = sprintf(
            '%d unique recipients, %d delivered (%s%%). %d delivery attempts (%sx per recipient): %d sent, %d deferred, %d bounced, %d expired.',
            $d['unique_recipients'], $t['recipients_delivered'], $t['recipient_delivery_rate'],
            $d['events_parsed'], $d['attempts_per_recipient'], $t['sent'], $t['deferred'], $t['bounced'], $t['expired'],
        );
        $out[] = 'Overall recommendation: '.$d['overall_recommendation'];
        $out[] = '';
        $out[] = sprintf('| %s | Recipients | Delivered | Deferred | Failed | Delivery %% | Attempts | Defer %% (attempts) | Retries/rcpt | Action |', 'domain' === $d['group_by'] ? 'Domain' : 'Provider');
        $out[] = '|---|---:|---:|---:|---:|---:|---:|---:|---:|---|';
        foreach ($d['groups'] as $g) {
            $out[] = sprintf('| %s | %d | %d | %d | %d | %s%% | %d | %s%% | %s | %s |',
                $g['key'], $g['unique_recipients'], $g['recipients_delivered'], $g['recipients_deferred'], $g['recipients_bounced'],
                $g['delivery_rate'], $g['total'], $g['deferral_rate'], $g['attempts_per_recipient'], $g['recommendation']);
        }
        $out[] = '';
        $out[] = [] === $d['alerts'] ? 'No alerts.' : "Alerts:\n".implode("\n", array_map(static fn (string $a): string => '- '.$a, $d['alerts']));

        $detail = [];
        foreach ($d['groups'] as $g) {
            if ([] === $g['top_reasons'] || $g['unique_recipients'] < 1) {
                continue;
            }
            $detail[] = sprintf("\n%s (%d recipients, %d attempts):", $g['key'], $g['unique_recipients'], $g['total']);
            foreach ($g['top_reasons'] as $r) {
                $detail[] = sprintf('- %s (%s) x%d: %s', $r['category'], $r['severity'], $r['count'], $r['sample']);
            }
        }
        if ([] !== $detail) {
            $out[] = "\nTop non-delivery reasons per group:".implode("\n", $detail);
        }

        return self::limit(implode("\n", $out));
    }

    /**
     * @param array<string, mixed> $d
     */
    public static function topDeferralReasons(array $d): string
    {
        $out = [self::heading('Top deferral reasons'.(null !== $d['provider_filter'] ? ' - '.$d['provider_filter'] : ''), $d['window'])];
        $out[] = sprintf('%d deferred attempts for %d recipients.', $d['deferred_attempts'], $d['deferred_recipients']);
        if ([] !== $d['by_category']) {
            $out[] = 'By category: '.implode(', ', array_map(static fn (string $k, int $v): string => "$k $v", array_keys($d['by_category']), $d['by_category']));
        }
        $out[] = '';
        foreach ($d['reasons'] as $i => $r) {
            $out[] = sprintf('%d. %s (%s), %d attempts, %d recipients, code %s, providers: %s',
                $i + 1, $r['category'], $r['severity'], $r['attempts'], $r['unique_recipients'],
                '' !== $r['remote_code'] ? $r['remote_code'] : 'n/a',
                implode(', ', array_map(static fn (string $k, int $v): string => "$k $v", array_keys($r['providers']), $r['providers'])));
            $out[] = '   '.$r['sample'];
        }

        return self::limit(implode("\n", $out));
    }

    /**
     * @param array<string, mixed> $d
     */
    public static function bounceBreakdown(array $d): string
    {
        $out = [self::heading('Bounce breakdown'.(null !== $d['provider_filter'] ? ' - '.$d['provider_filter'] : ''), $d['window'])];
        $out[] = sprintf('%d hard failures for %d recipients (%s%% of attempts, %s%% of recipients in scope).',
            $d['bounced_attempts'], $d['bounced_recipients'], $d['bounce_rate'], $d['recipient_bounce_rate']);
        if ([] === $d['by_dsn']) {
            return implode("\n", $out);
        }
        $out[] = '';
        $out[] = '| DSN | Meaning | Count | Recipients | Share | Categories | Providers |';
        $out[] = '|---|---|---:|---:|---:|---|---|';
        foreach ($d['by_dsn'] as $r) {
            $out[] = sprintf('| %s | %s | %d | %d | %s%% | %s | %s |', $r['dsn'], $r['meaning'], $r['count'], $r['unique_recipients'], $r['share'],
                self::kv($r['categories']), self::kv($r['providers']));
        }
        $out[] = '';
        foreach ($d['by_dsn'] as $r) {
            $out[] = sprintf('- %s sample: %s', $r['dsn'], $r['sample']);
        }

        return self::limit(implode("\n", $out));
    }

    /**
     * @param array<string, mixed> $d
     */
    public static function recentRejections(array $d): string
    {
        $out = [self::heading('Recent rejections'.(null !== $d['provider_filter'] ? ' - '.$d['provider_filter'] : ''), $d['window'])];
        $out[] = sprintf('Showing %d of %d%s.', $d['returned'], $d['matched'], $d['include_temporary'] ? ' (temporary deferrals included)' : '');
        $out[] = '';
        foreach ($d['rejections'] as $r) {
            $out[] = sprintf('- %s %s %s (%s) %s dsn=%s code=%s %s/%s', $r['time'] ?? '-', $r['queue_id'], $r['recipient'], $r['provider'],
                $r['status'], $r['dsn'], '' !== $r['remote_code'] ? $r['remote_code'] : 'n/a', $r['category'], $r['severity']);
            $out[] = '  '.mb_substr($r['reason'], 0, 300);
        }

        return self::limit(implode("\n", $out));
    }

    /**
     * @param array<string, mixed> $d
     */
    public static function logSourceInfo(array $d): string
    {
        $out = ['## Log source', ''];
        $out[] = sprintf('Path: %s (%s)%s', $d['log_path'], $d['mode'], $d['using_bundled_sample'] ? ', bundled sample log' : '');
        foreach ($d['files'] as $f) {
            $out[] = sprintf('- %s, %d bytes%s', $f['file'], $f['bytes'], $f['gzip'] ? ', gzip' : '');
        }
        $out[] = sprintf('Lines read: %d. Delivery events: %d (%d without timestamp).', $d['lines_read'], $d['delivery_events'], $d['events_without_timestamp']);
        $out[] = sprintf('Covers: %s to %s', $d['first_event'] ?? '-', $d['last_event'] ?? '-');
        $out[] = sprintf('Provider map: %d mappings%s', $d['provider_mappings'], null !== $d['provider_map_file'] ? ', extra entries from '.$d['provider_map_file'] : ', built-in only');

        return implode("\n", $out);
    }

    /**
     * @param array<string, mixed> $w
     */
    private static function heading(string $title, array $w): string
    {
        return sprintf("## %s\n\nWindow: %s ending %s (%s) - %s to %s, %d delivery events.\n",
            $title, $w['since'], $w['anchor'], $w['anchor'] === 'latest' ? 'newest log entry' : 'wall clock',
            $w['from'] ?? 'start of log', $w['to'] ?? '-', $w['events_in_window']);
    }

    /**
     * @param array<string, int> $a
     */
    private static function kv(array $a): string
    {
        return implode(', ', array_map(static fn (string $k, int $v): string => "$k $v", array_keys($a), $a));
    }

    private static function limit(string $text): string
    {
        if (\strlen($text) <= self::LIMIT) {
            return $text;
        }

        return substr($text, 0, self::LIMIT - 200)."\n\n[Truncated. The structured content has the full result; narrow the window or filter by provider.]";
    }
}
