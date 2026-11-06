<?php

declare(strict_types=1);

namespace MailcowDeliverability\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Starts bin/server.php over stdio and drives it with bin/smoke-test.php:
 * initialize, tools/list, and a tools/call for every tool.
 */
final class StdioServerTest extends TestCase
{
    public function testSmokeTestPasses(): void
    {
        $root = \dirname(__DIR__, 2);
        $cmd = escapeshellarg(\PHP_BINARY).' '.escapeshellarg($root.'/bin/smoke-test.php').' 2>&1';
        exec($cmd, $out, $code);
        $output = implode("\n", $out);

        self::assertSame(0, $code, $output);
        self::assertStringContainsString('All checks passed', $output);
        self::assertStringContainsString('provider_reputation, top_deferral_reasons, bounce_breakdown, recent_rejections, log_source_info', $output);
    }
}
