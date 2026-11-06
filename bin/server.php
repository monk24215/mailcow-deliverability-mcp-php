#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * stdio entry point. MCP clients launch this as a subprocess.
 * Configuration comes from environment variables, see src/Config.php.
 * Nothing may be written to STDOUT except protocol messages.
 */

require dirname(__DIR__).'/vendor/autoload.php';

use MailcowDeliverability\Config;
use MailcowDeliverability\ServerFactory;
use Mcp\Server\Transport\StdioTransport;
use Psr\Log\AbstractLogger;

$logger = new class extends AbstractLogger {
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (\in_array($level, ['debug', 'info', 'notice'], true) && !getenv('MCP_DEBUG')) {
            return;
        }
        fwrite(\STDERR, sprintf("[%s] %s\n", strtoupper((string) $level), $message));
    }
};

$server = ServerFactory::create(Config::fromEnvironment(), $logger);

exit($server->run(new StdioTransport(logger: $logger)));
