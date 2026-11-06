<?php

declare(strict_types=1);

namespace MailcowDeliverability\Mcp;

use MailcowDeliverability\Analysis\Reports;
use MailcowDeliverability\DeliveryLog;
use Mcp\Capability\Attribute\Schema;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Content\TextContent;
use Mcp\Schema\Result\CallToolResult;

/**
 * MCP tool handlers. Each returns a Markdown summary as text content and the
 * full result as structuredContent.
 */
final class DeliverabilityTools
{
    public function __construct(private readonly DeliveryLog $log)
    {
    }

    /**
     * Per-provider delivery outcomes from the Postfix log: delivered / deferred / bounced counts and rates for each receiving mailbox provider (Google, Microsoft, Yahoo/AOL, Apple, ...), alerts, and a per-provider send recommendation (increase / hold / reduce / stop / insufficient_data).
     *
     * Counts are given two ways. Attempt counts (total, sent, deferred, bounced) are Postfix delivery attempts; recipient counts (unique_recipients, recipients_delivered, recipients_deferred, recipients_bounced, delivery_rate) are people. Postfix retries a deferred address for days, so judge reputation on the recipient fields and use attempts_per_recipient (>= 5) to spot retry storms. No recommendation is given below 20 unique recipients.
     *
     * Failure categories: blocklisted, reputation_block, auth_failure (critical); throttled, connection_error, tls_error (warning); greylisted, user_unknown, mailbox_full, domain_error, other (info).
     *
     * @param string $since          Look-back window: "90m", "24h", "7d", "2w" or "all"
     * @param string $group_by       Group by mailbox "provider" or by recipient "domain"
     * @param int    $min_recipients Omit groups with fewer unique recipients than this
     * @param string $anchor         Where the window ends: "latest" (newest log entry) or "now" (wall clock)
     */
    public function providerReputation(
        string $since = '24h',
        #[Schema(enum: ['provider', 'domain'])]
        string $group_by = 'provider',
        #[Schema(minimum: 1)]
        int $min_recipients = 1,
        #[Schema(enum: ['latest', 'now'])]
        string $anchor = 'latest',
    ): CallToolResult {
        return $this->run(function () use ($since, $group_by, $min_recipients, $anchor): CallToolResult {
            $w = $this->log->window($since, $anchor);
            $data = ['window' => $this->windowInfo($since, $anchor, $w)] + Reports::providerReputation($w['events'], $group_by, $min_recipients);

            return $this->result(Markdown::providerReputation($data), $data);
        });
    }

    /**
     * Most frequent reasons receiving servers gave for deferring (temporarily refusing) mail, classified and with volatile IDs/IPs collapsed so the same response groups together. Each reason lists attempts, unique recipients, the providers it came from, the remote SMTP code, and first/last seen.
     *
     * Note that the remote server's own SMTP code decides permanence: a deferral whose text carries a 5xx code (Postfix logs connect-stage refusals this way) is classified as a refusal, not a throttle.
     *
     * @param string  $since    Look-back window: "90m", "24h", "7d", "2w" or "all"
     * @param ?string $provider Restrict to one provider name (e.g. "Google") or recipient domain
     * @param int     $limit    Maximum number of reasons to return
     * @param string  $anchor   Where the window ends: "latest" (newest log entry) or "now" (wall clock)
     */
    public function topDeferralReasons(
        string $since = '24h',
        ?string $provider = null,
        #[Schema(minimum: 1, maximum: 100)]
        int $limit = 10,
        #[Schema(enum: ['latest', 'now'])]
        string $anchor = 'latest',
    ): CallToolResult {
        return $this->run(function () use ($since, $provider, $limit, $anchor): CallToolResult {
            $w = $this->log->window($since, $anchor);
            $data = ['window' => $this->windowInfo($since, $anchor, $w)] + Reports::topDeferralReasons($w['events'], $provider, $limit);

            return $this->result(Markdown::topDeferralReasons($data), $data);
        });
    }

    /**
     * Hard failures (status=bounced or expired) grouped by DSN code (e.g. 5.1.1, 5.7.1), each with its RFC 3463 meaning, attempt and recipient counts, classification categories, affected providers, the remote SMTP codes seen, and a sample response.
     *
     * @param string  $since    Look-back window: "90m", "24h", "7d", "2w" or "all"
     * @param ?string $provider Restrict to one provider name (e.g. "Microsoft") or recipient domain
     * @param string  $anchor   Where the window ends: "latest" (newest log entry) or "now" (wall clock)
     */
    public function bounceBreakdown(
        string $since = '24h',
        ?string $provider = null,
        #[Schema(enum: ['latest', 'now'])]
        string $anchor = 'latest',
    ): CallToolResult {
        return $this->run(function () use ($since, $provider, $anchor): CallToolResult {
            $w = $this->log->window($since, $anchor);
            $data = ['window' => $this->windowInfo($since, $anchor, $w)] + Reports::bounceBreakdown($w['events'], $provider);

            return $this->result(Markdown::bounceBreakdown($data), $data);
        });
    }

    /**
     * The newest individual refusals, newest first: bounces, expiries, and deferrals where the remote server answered with a 5xx code or named a blocklist. Each row has time, queue ID, recipient, provider, status, DSN, remote SMTP code, category, relay and the full response text. Set include_temporary to also list ordinary 4xx deferrals (throttling, greylisting).
     *
     * @param string  $since             Look-back window: "90m", "24h", "7d", "2w" or "all"
     * @param int     $limit             Maximum number of rows
     * @param bool    $include_temporary Also include 4xx deferrals
     * @param ?string $provider          Restrict to one provider name or recipient domain
     * @param string  $anchor            Where the window ends: "latest" (newest log entry) or "now" (wall clock)
     */
    public function recentRejections(
        string $since = '7d',
        #[Schema(minimum: 1, maximum: 500)]
        int $limit = 20,
        bool $include_temporary = false,
        ?string $provider = null,
        #[Schema(enum: ['latest', 'now'])]
        string $anchor = 'latest',
    ): CallToolResult {
        return $this->run(function () use ($since, $limit, $include_temporary, $provider, $anchor): CallToolResult {
            $w = $this->log->window($since, $anchor);
            $data = ['window' => $this->windowInfo($since, $anchor, $w)] + Reports::recentRejections($w['events'], $limit, $include_temporary, $provider);

            return $this->result(Markdown::recentRejections($data), $data);
        });
    }

    /**
     * Shows which Postfix log the server is reading: the configured path (file or directory of rotated logs, .gz included), the files read in order, line and delivery-event counts, the time span covered, and the provider map in use. Configure with the MAILLOG_PATH, PROVIDER_MAP_FILE and MAILLOG_TIMEZONE environment variables.
     */
    public function logSourceInfo(): CallToolResult
    {
        return $this->run(function (): CallToolResult {
            $data = $this->log->describe();

            return $this->result(Markdown::logSourceInfo($data), $data);
        });
    }

    /**
     * @param array{events: list<mixed>, from: ?\DateTimeImmutable, to: ?\DateTimeImmutable} $w
     *
     * @return array<string, mixed>
     */
    private function windowInfo(string $since, string $anchor, array $w): array
    {
        return [
            'since' => $since,
            'anchor' => $anchor,
            'from' => $w['from']?->format(\DATE_ATOM),
            'to' => $w['to']?->format(\DATE_ATOM),
            'events_in_window' => \count($w['events']),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function result(string $markdown, array $data): CallToolResult
    {
        return new CallToolResult([new TextContent($markdown)], false, $data);
    }

    /**
     * @param callable(): CallToolResult $fn
     */
    private function run(callable $fn): CallToolResult
    {
        try {
            return $fn();
        } catch (\InvalidArgumentException|\RuntimeException|\JsonException $e) {
            throw new ToolCallException($e->getMessage(), 0, $e);
        }
    }
}
