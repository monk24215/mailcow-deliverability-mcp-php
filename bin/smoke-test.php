#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * End-to-end check over stdio: starts bin/server.php as a subprocess, performs
 * the MCP initialize handshake, lists tools, and calls each tool against the
 * configured log (the bundled sample by default). Exits non-zero on failure.
 *
 *   php bin/smoke-test.php            summary
 *   php bin/smoke-test.php --verbose  also print each tool's text output
 */

$verbose = in_array('--verbose', $argv, true);
$root = dirname(__DIR__);

$proc = proc_open(
    [\PHP_BINARY, $root.'/bin/server.php'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $root,
);
if (!is_resource($proc)) {
    fwrite(\STDERR, "Could not start bin/server.php\n");
    exit(1);
}
stream_set_blocking($pipes[2], false);

$id = 0;
$send = static function (string $method, array $params = [], bool $notification = false) use (&$id, $pipes): ?int {
    $msg = ['jsonrpc' => '2.0', 'method' => $method];
    if ([] !== $params) {
        $msg['params'] = $params;
    }
    if (!$notification) {
        $msg['id'] = ++$id;
    }
    fwrite($pipes[0], json_encode($msg, \JSON_UNESCAPED_SLASHES)."\n");
    fflush($pipes[0]);

    return $notification ? null : $id;
};
$receive = static function (int $wantId) use ($pipes): array {
    $deadline = microtime(true) + 30;
    while (microtime(true) < $deadline) {
        $line = fgets($pipes[1]);
        if (false === $line) {
            usleep(10000);
            continue;
        }
        $msg = json_decode($line, true);
        if (is_array($msg) && ($msg['id'] ?? null) === $wantId) {
            return $msg;
        }
    }
    throw new RuntimeException("No response for request $wantId");
};

$failures = 0;
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? 'PASS ' : 'FAIL ').$label."\n";
    if (!$ok) {
        ++$failures;
    }
};

try {
    $init = $receive($send('initialize', [
        'protocolVersion' => '2025-06-18',
        'capabilities' => new stdClass(),
        'clientInfo' => ['name' => 'smoke-test', 'version' => '1.0'],
    ]));
    $check(isset($init['result']['serverInfo']['name']), sprintf('initialize -> %s %s, protocol %s',
        $init['result']['serverInfo']['name'] ?? '?', $init['result']['serverInfo']['version'] ?? '?', $init['result']['protocolVersion'] ?? '?'));
    $send('notifications/initialized', [], true);

    $list = $receive($send('tools/list'));
    $names = array_column($list['result']['tools'] ?? [], 'name');
    $check(5 === count($names), 'tools/list -> '.implode(', ', $names));

    $calls = [
        ['log_source_info', []],
        ['provider_reputation', ['since' => '24h']],
        ['top_deferral_reasons', ['since' => '7d', 'limit' => 5]],
        ['bounce_breakdown', ['since' => 'all']],
        ['recent_rejections', ['since' => '24h', 'limit' => 3]],
        ['provider_reputation', ['since' => 'yesterday']], // invalid on purpose
    ];
    foreach ($calls as [$tool, $args]) {
        $res = $receive($send('tools/call', ['name' => $tool, 'arguments' => (object) $args]));
        $isError = (bool) ($res['result']['isError'] ?? isset($res['error']));
        $text = $res['result']['content'][0]['text'] ?? ($res['error']['message'] ?? '');
        $expectError = 'yesterday' === ($args['since'] ?? null);
        $structured = isset($res['result']['structuredContent']);
        $check($expectError ? $isError : (!$isError && $structured), sprintf('tools/call %s %s -> %s', $tool, json_encode($args),
            $expectError ? 'error as expected: '.strtok($text, "\n") : ($isError ? 'ERROR: '.$text : strtok($text, "\n").' (+structuredContent)')));
        if ($verbose && !$expectError) {
            echo "\n".$text."\n\n";
        }
    }
} catch (Throwable $e) {
    $check(false, $e->getMessage());
}

fclose($pipes[0]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($proc);
if ($failures > 0 && '' !== trim((string) $stderr)) {
    fwrite(\STDERR, "Server stderr:\n".$stderr);
}

echo $failures > 0 ? "\n$failures check(s) failed\n" : "\nAll checks passed\n";
exit($failures > 0 ? 1 : 0);
