# mailcow-deliverability-mcp-php

An MCP server, written in PHP on the official [PHP MCP SDK](https://github.com/modelcontextprotocol/php-sdk) (`mcp/sdk`), that reads Postfix delivery logs and reports sending reputation per receiving mailbox provider (Google, Microsoft, Yahoo/AOL, Apple, and so on).

It is a port of the deliverability layer of [mailcow-mpc-showcase](https://github.com/monk24215/mailcow-mpc-showcase), a TypeScript MCP server for Mailcow. The TypeScript project reads the log through the Mailcow API or over SSH; this one reads log files on disk, so it works with any Postfix install, Mailcow or not.

A synthetic, anonymized sample log is bundled under `examples/logs/` and is used by default, so the server returns results with no configuration.

- PHP 8.2 or later, with the `json`, `mbstring` and `zlib` extensions
- stdio transport, plus Streamable HTTP through `public/index.php`
- Five read-only tools; every result has a Markdown summary as text content and the full data as `structuredContent`

## Quick start

```bash
git clone https://github.com/monk24215/mailcow-deliverability-mcp-php.git
cd mailcow-deliverability-mcp-php
composer install
composer smoke
```

`composer smoke` starts the server over stdio, runs `initialize`, `tools/list` and a `tools/call` for each tool against the sample log, and prints PASS/FAIL per step. Add `-- --verbose` to see each tool's output.

To try it in an MCP client, add this to Claude Desktop's config (Settings, Developer, Edit Config) and restart the app:

```json
{
    "mcpServers": {
        "mailcow-deliverability": {
            "command": "php",
            "args": ["/absolute/path/to/mailcow-deliverability-mcp-php/bin/server.php"]
        }
    }
}
```

Claude Code:

```bash
claude mcp add mailcow-deliverability -- php /absolute/path/to/mailcow-deliverability-mcp-php/bin/server.php
```

MCP Inspector:

```bash
npx @modelcontextprotocol/inspector php bin/server.php
```

Then ask, for example: "Give me a provider reputation snapshot for the last 24 hours", "Why is Microsoft refusing mail?", "Which addresses should I suppress?".

### Docker

```bash
docker build -t mailcow-deliverability-mcp .
docker run -i --rm mailcow-deliverability-mcp
```

Client config for the image:

```json
{
    "mcpServers": {
        "mailcow-deliverability": {
            "command": "docker",
            "args": ["run", "-i", "--rm", "mailcow-deliverability-mcp"]
        }
    }
}
```

### HTTP

```bash
composer serve-http        # php -S 127.0.0.1:8080 public/index.php
```

The endpoint is `http://127.0.0.1:8080/mcp`. Sessions are stored in `var/sessions/` because the built-in web server runs each request in a new process. The SDK's default middleware accepts localhost only.

## Using your own logs

Configuration is by environment variable:

| Variable | Default | Meaning |
|---|---|---|
| `MAILLOG_PATH` | `examples/logs` | A Postfix log file, or a directory of rotated logs. In a directory, files named like `mail.log`, `mail.log.1`, `mail.log.2.gz`, `maillog-20261101` or `*.log` are read oldest rotation first. `.gz` files are decompressed on the fly. |
| `PROVIDER_MAP_FILE` | none (the sample map when `MAILLOG_PATH` is unset) | JSON object of extra `"domain": "Provider"` entries, merged over the built-in map. Useful for Google Workspace or Microsoft 365 customer domains. |
| `MAILLOG_TIMEZONE` | `UTC` | Time zone for syslog timestamps that carry none (`Nov  3 09:12:01`). RFC 3339 timestamps keep their own offset. |

```json
{
    "mcpServers": {
        "mailcow-deliverability": {
            "command": "php",
            "args": ["/absolute/path/to/mailcow-deliverability-mcp-php/bin/server.php"],
            "env": { "MAILLOG_PATH": "/var/log/mail" }
        }
    }
}
```

With Docker, mount the directory read-only: `docker run -i --rm -v /var/log/mail:/logs:ro -e MAILLOG_PATH=/logs mailcow-deliverability-mcp`.

For Mailcow, Postfix logs to the `postfix-mailcow` container's output. One way to get a file is `docker compose logs --no-color --no-log-prefix --timestamps postfix-mailcow > postfix.log`; the `--timestamps` prefix is RFC 3339 and is parsed.

The log is re-read when a file's size or modification time changes, so a live log can be pointed at directly.

## Time windows

Every analysis tool takes `since`: `90m`, `24h`, `7d`, `2w` or `all`. The window ends at the newest entry in the log by default (`anchor: "latest"`), so an archived log, or the bundled sample, still has a meaningful "last 24 hours". Pass `anchor: "now"` to measure against the current time instead.

## Two rules the analysis follows

These come from the TypeScript project and are kept as is.

**Count recipients, not attempts.** Postfix retries a deferred address for days, so one refused address can produce dozens of attempts. Every group reports both: `unique_recipients`, `recipients_delivered`, `recipients_deferred`, `recipients_bounced` and `delivery_rate` are people; `total`, `sent`, `deferred`, `bounced` and `attempts_per_recipient` are attempts. A recipient delivered after one or more deferrals counts as delivered. No recommendation is given for a provider with fewer than 20 unique recipients.

**Classify the response, and let the remote server's SMTP code decide permanence.** Each non-delivery response is put in one category:

| Category | Severity | Meaning |
|---|---|---|
| `blocklisted` | critical | A DNS blocklist is named. Checked first, at any SMTP class. |
| `reputation_block` | critical | 5xx policy or reputation refusal |
| `auth_failure` | critical | SPF, DKIM or DMARC failed at the receiver |
| `throttled` | warning | 4xx rate or reputation signal |
| `connection_error` | warning | Timeout, refused or lost connection |
| `tls_error` | warning | Certificate or handshake problem |
| `greylisted` | info | Greylisting; retries normally succeed |
| `user_unknown` | info | Address does not exist |
| `mailbox_full` | info | Recipient over quota |
| `domain_error` | info | Domain has no MX or does not resolve |
| `other` | info | No pattern matched |

Postfix logs a connect-stage refusal (`refused to talk to me: 554 ...`) as a deferral with a 4.x.x DSN, so the code in the response text is used before the DSN. A 4xx response is never classified as a permanent block, even when its text says "unsolicited" (Gmail's 421-4.7.28) or "IP reputation" (Microsoft's 451 4.7.650); those are `throttled`.

## Tools

All tools are annotated read-only, idempotent and closed-world.

### `provider_reputation`

Delivery outcomes per provider (or per recipient domain), alerts, and a recommendation per provider: `increase` (clean across 50+ recipients), `hold`, `reduce` (2%+ of recipients failed, or 10%+ undelivered), `stop` (a critical category in the top reasons), or `insufficient_data` (under 20 recipients). The overall recommendation is the most severe among providers that have enough data.

Arguments: `since` (default `24h`), `group_by` (`provider` or `domain`), `min_recipients` (default 1), `anchor` (`latest` or `now`).

Text content for `{"since": "24h"}` against the sample log (alerts shortened):

```
## Provider reputation

Window: 24h ending latest (newest log entry) - 2026-11-02T23:49:39+00:00 to 2026-11-03T23:49:39+00:00, 597 delivery events.

342 unique recipients, 296 delivered (86.55%). 597 delivery attempts (1.7x per recipient): 296 sent, 261 deferred, 40 bounced, 0 expired.
Overall recommendation: stop

| Provider | Recipients | Delivered | Deferred | Failed | Delivery % | Attempts | Defer % (attempts) | Retries/rcpt | Action |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---|
| Google | 108 | 107 | 0 | 1 | 99.07% | 127 | 14.96% | 1.2 | increase |
| Microsoft | 89 | 56 | 0 | 33 | 62.92% | 92 | 3.26% | 1 | stop |
| Yahoo/AOL | 73 | 72 | 0 | 1 | 98.63% | 84 | 13.1% | 1.2 | increase |
| Apple | 25 | 25 | 0 | 0 | 100% | 25 | 0% | 1 | hold |
| Comcast | 14 | 14 | 0 | 0 | 100% | 16 | 12.5% | 1.1 | insufficient_data |
| GMX/Web.de | 11 | 10 | 0 | 1 | 90.91% | 21 | 47.62% | 1.9 | insufficient_data |
| orange.example.org | 5 | 0 | 5 | 0 | 0% | 182 | 100% | 36.4 | insufficient_data |
| acme.example.com | 4 | 4 | 0 | 0 | 100% | 8 | 50% | 2 | insufficient_data |
| globex.example.net | 4 | 4 | 0 | 0 | 100% | 8 | 50% | 2 | insufficient_data |
| initech.example.org | 4 | 4 | 0 | 0 | 100% | 8 | 50% | 2 | insufficient_data |
| smallisp.example.net | 3 | 0 | 0 | 3 | 0% | 3 | 0% | 1 | insufficient_data |
| nxdomain.example.net | 1 | 0 | 0 | 1 | 0% | 1 | 0% | 1 | insufficient_data |
| refuser.example.net | 1 | 0 | 1 | 0 | 0% | 22 | 100% | 22 | insufficient_data |

Alerts:
- Microsoft: 33/89 recipients failed (37.08%).
- Microsoft (89 recipients): reputation_block - "host mx1.outlook.example.com[<ip>] said: 550 5.7.1 Unfortunately, messages from [<ip>] weren't sent. Please contact your Internet service provider since part of their network is on our block list (S3150). You can also re"
- Microsoft (89 recipients): reputation_block - "host mx1.live.example.com[<ip>] said: 550 5.7.1 Unfortunately, messages from [<ip>] weren't sent. Please contact your Internet service provider since part of their network is on our block list (S3150). You can also refer"
...
```

`structuredContent` (one group shown):

```json
{
  "window": {
    "since": "24h",
    "anchor": "latest",
    "from": "2026-11-02T23:49:39+00:00",
    "to": "2026-11-03T23:49:39+00:00",
    "events_in_window": 597
  },
  "group_by": "provider",
  "events_parsed": 597,
  "unique_recipients": 342,
  "attempts_per_recipient": 1.7,
  "totals": {
    "sent": 296,
    "deferred": 261,
    "bounced": 40,
    "expired": 0,
    "deferral_rate": 43.72,
    "bounce_rate": 6.7,
    "recipients_delivered": 296,
    "recipient_delivery_rate": 86.55
  },
  "overall_recommendation": "stop",
  "groups": [
    {
      "key": "Google",
      "total": 127,
      "sent": 107,
      "deferred": 19,
      "bounced": 1,
      "expired": 0,
      "deferral_rate": 14.96,
      "bounce_rate": 0.79,
      "unique_recipients": 108,
      "recipients_delivered": 107,
      "recipients_bounced": 1,
      "recipients_deferred": 0,
      "delivery_rate": 99.07,
      "attempts_per_recipient": 1.2,
      "top_reasons": [
        {
          "category": "throttled",
          "severity": "warning",
          "count": 15,
          "sample": "host mx1.gmail.example.com[<ip>] said: 421-4.7.28 [<ip> 15] Our system has detected an unu..."
        }
      ],
      "failed_rate": 0.93,
      "undelivered_rate": 0,
      "recommendation": "increase"
    }
  ],
  "alerts": [
    "..."
  ]
}
```

### `top_deferral_reasons`

Deferred attempts grouped by category and normalized response text (IP addresses and message IDs collapsed), most frequent first. Each reason has attempts, unique recipients, providers, the remote SMTP code, the DSN, and first/last seen.

Arguments: `since` (default `24h`), `provider` (provider name or recipient domain), `limit` (default 10), `anchor`.

```
## Top deferral reasons

Window: 24h ending latest (newest log entry) - 2026-11-02T23:49:39+00:00 to 2026-11-03T23:49:39+00:00, 597 delivery events.

261 deferred attempts for 56 recipients.
By category: reputation_block 204, throttled 31, greylisted 22, mailbox_full 4

1. reputation_block (critical), 182 attempts, 5 recipients, code 550, providers: orange.example.org 182
   host mx1.orange.example.org[<ip>] refused to talk to me: 550 mwinf5c42 ME Service refuse. Service refused. OFR006_103 [103]
2. reputation_block (critical), 22 attempts, 1 recipients, code 550, providers: refuser.example.net 22
   host mx1.refuser.example.net[<ip>] refused to talk to me: 550 [S10] Blocked
3. throttled (warning), 15 attempts, 12 recipients, code 421, providers: Google 15
...
```

### `bounce_breakdown`

Hard failures (`status=bounced` or `expired`) grouped by DSN code, with the RFC 3463 meaning, attempts, recipients, share of all bounces, categories, providers, remote SMTP codes and a sample response.

Arguments: `since` (default `24h`), `provider`, `anchor`.

```
## Bounce breakdown

Window: 24h ending latest (newest log entry) - 2026-11-02T23:49:39+00:00 to 2026-11-03T23:49:39+00:00, 597 delivery events.

40 hard failures for 40 recipients (6.7% of attempts, 11.7% of recipients in scope).

| DSN | Meaning | Count | Recipients | Share | Categories | Providers |
|---|---|---:|---:|---:|---|---|
| 5.7.1 | Delivery not authorized, message refused | 35 | 35 | 87.5% | reputation_block 32, blocklisted 3 | Microsoft 32, smallisp.example.net 3 |
| 5.0.0 | Undefined permanent failure | 1 | 1 | 2.5% | user_unknown 1 | Yahoo/AOL 1 |
| 5.1.1 | Bad destination mailbox address | 1 | 1 | 2.5% | user_unknown 1 | GMX/Web.de 1 |
| 5.2.2 | Mailbox full | 1 | 1 | 2.5% | mailbox_full 1 | Google 1 |
| 5.4.4 | Unable to route (no MX / host not found) | 1 | 1 | 2.5% | domain_error 1 | nxdomain.example.net 1 |
| 5.5.0 | Protocol status (other) | 1 | 1 | 2.5% | user_unknown 1 | Microsoft 1 |

```

### `recent_rejections`

Individual refusals, newest first: bounces, expiries, and deferrals where the remote answered 5xx or named a blocklist. With `include_temporary: true`, every other deferral is listed too. Each row has time, queue ID, recipient, provider, status, DSN, remote code, category, severity, relay and the full response.

Arguments: `since` (default `7d`), `limit` (default 20), `include_temporary` (default false), `provider`, `anchor`.

Text content for `{"since": "24h", "limit": 3, "provider": "Microsoft"}`:

```
## Recent rejections - Microsoft

Window: 24h ending latest (newest log entry) - 2026-11-02T23:49:39+00:00 to 2026-11-03T23:49:39+00:00, 597 delivery events.

Showing 3 of 33.

- 2026-11-03T15:19:13+00:00 8200CCDCE8 subscriber-0261@outlook.example.com (Microsoft) bounced dsn=5.7.1 code=550 reputation_block/critical
  host mx1.outlook.example.com[198.51.100.22] said: 550 5.7.1 Unfortunately, messages from [192.0.2.10] weren't sent. Please contact your Internet service provider since part of their network is on our block list (S3150). You can also refer your provider to https://postmaster.example.com/troubleshooti
```

### `log_source_info`

Which log is loaded: path, files in reading order, lines read, delivery events, time span, and the provider map in use. No arguments.

```
## Log source

Path: /path/to/mailcow-deliverability-mcp-php/examples/logs (directory), bundled sample log
- /path/to/mailcow-deliverability-mcp-php/examples/logs/mail.log.2.gz, 68290 bytes, gzip
- /path/to/mailcow-deliverability-mcp-php/examples/logs/mail.log.1, 519469 bytes
- /path/to/mailcow-deliverability-mcp-php/examples/logs/mail.log, 549555 bytes
Lines read: 8944. Delivery events: 1592 (0 without timestamp).
Covers: 2026-11-01T08:00:07+00:00 to 2026-11-03T23:49:39+00:00
Provider map: 50 mappings, extra entries from /path/to/mailcow-deliverability-mcp-php/examples/providers.sample.json
```

## The sample log

`examples/logs/` holds three days (2026-11-01 to 2026-11-03, UTC) of a synthetic newsletter sender: `mail.log.2.gz`, `mail.log.1` and `mail.log`, about 8,900 lines and 1,600 delivery attempts in total, including submission, cleanup, qmgr, bounce and inbound postscreen/smtpd lines that the parser skips. It is generated by `tools/generate-sample-log.php` with a fixed seed.

All addresses, domains and hosts are under `example.com`, `example.net` and `example.org`; all IP addresses are from the documentation ranges (192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24). Response texts are modelled on the wording large providers use, with hosts, URLs and IDs replaced. Because the recipient domains are not real provider domains, `examples/providers.sample.json` maps them to providers; it is only loaded when `MAILLOG_PATH` is unset.

What it contains:

- Google: intermittent 421-4.7.28 throttling, heavier on the afternoon of day 3; retries succeed. A few unknown users and full mailboxes.
- Microsoft: occasional 451 4.7.650 throttling, then from day 3 09:00 about a third of messages refused with 550 5.7.1 (S3150).
- Yahoo/AOL: 421 4.7.0 [TSS04] deferrals, most retried successfully.
- Apple, Comcast, GMX: mostly clean; Comcast throttles, GMX greylists.
- Three company domains that greylist the first attempt.
- One small ISP that rejects with a DNS blocklist hit, one unmapped domain that refuses at connect time with an OFR code, one address deferred for the whole period (a retry storm), one domain with no DNS.

## Tests

```bash
composer test
```

PHPUnit covers the log parser (both timestamp formats, year inference for syslog lines, field extraction, lines it must skip), the classifier (the cases from the TypeScript `parser-test.mjs`, including its real-world rejection strings with hosts anonymized), aggregation (provider grouping, the retry-storm case, recipient outcomes), each report, time windows, the log source (rotation order, gzip, date-suffixed names), the sample log, and an end-to-end stdio run of `bin/smoke-test.php`.

## How it maps to the TypeScript project

| TypeScript (`mailcow-mpc-showcase`) | This project |
|---|---|
| `src/services/analysis.ts`: `parseDeliveryEvents` | `src/Log/PostfixLogParser.php` |
| `src/services/analysis.ts`: `classifyReason`, `extractSmtpCode`, `normalizeReason` | `src/Analysis/ReasonClassifier.php` |
| `src/services/analysis.ts`: `aggregateDeliveries` | `src/Analysis/DeliveryAggregator.php` |
| `src/constants.ts`: `PROVIDER_MAP`, `REASON_PATTERNS` | `ProviderMap::DEFAULTS`, `ReasonClassifier::PATTERNS` (same entries, same order) |
| `reputation_delivery_breakdown` | `provider_reputation` (same fields, alerts and thresholds) |
| `reputation_warmup_status` | the per-provider `recommendation` in `provider_reputation` (same rules). The rate-limit section is not ported; it needs the Mailcow API. |
| `reputation_snapshot` | Not ported. Its delivery part is `provider_reputation`; containers, queue, Rspamd and bans need the Mailcow API or SSH. |
| `reputation_auth_audit` | Not ported (needs the Mailcow API and DNS). Receiver-side SPF/DKIM/DMARC failures appear as `auth_failure` in the other tools. |
| log via Mailcow API (`logs/postfix`) or `docker logs` over SSH | log files on disk: one file or a rotated directory, `.gz` included |
| `scripts/parser-test.mjs` | `tests/Unit/*` |
| (none) | `top_deferral_reasons`, `bounce_breakdown`, `recent_rejections`, `log_source_info` |

Differences in behaviour:

- `relay` is filled in. In the TypeScript regex the optional `relay=` group follows a lazy `.*?`, so it never captures and `relay` is always empty; here it is extracted with a separate pattern.
- `user_unknown` also matches "doesn't have a ... account", which the TypeScript table classified as `other`.
- Windows are applied to each event's timestamp. The TypeScript project's API source limits by number of log lines instead.

## Issues found in the SDK

Found while building this against `mcp/sdk` v0.9.0; also present on `main` at the time of writing (commit 7442aee).

- **The session-end listener runs twice when a stdio client disconnects.** `StdioTransport::listen()` calls `handleSessionEnd()` when the input stream closes, and `Server::run()` then calls `$transport->close()`, which calls `handleSessionEnd()` again. `Protocol::destroySession()` therefore runs twice for one session, which shows as two "Session destroyed." log lines. With the in-memory store this is harmless; a custom `SessionStoreInterface` or session lock sees two destroy calls. To reproduce: `printf '%s\n' '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}' | MCP_DEBUG=1 php bin/server.php > /dev/null`.

## Limitations

- Only Postfix delivery lines (`to=<...>, ... dsn=..., status=...`) are analysed. Complaint rates, which matter most at Gmail and Yahoo, never reach the MTA log; use the providers' postmaster tools for those.
- Expired messages are counted only when Postfix writes a `status=expired` line with a recipient; the usual `status=expired, returned to sender` line on the qmgr record has no recipient and is skipped, as in the TypeScript project.
- The provider map covers the major consumer mailbox providers. Other domains group under their own name unless added through `PROVIDER_MAP_FILE`.
- Syslog timestamps have no year; it is inferred from the current date.

## License

MIT. See `LICENSE`.

## Acknowledgments

Built by Hark (hark.com) with Kristian Oubre.
