<?php

declare(strict_types=1);

namespace MailcowDeliverability;

/**
 * Runtime configuration, read from environment variables.
 *
 *   MAILLOG_PATH       Postfix log file, or a directory of rotated logs (.gz allowed).
 *                      Default: the bundled sample in examples/logs.
 *   PROVIDER_MAP_FILE  Optional JSON file of extra "domain": "Provider" mappings.
 *                      Default: examples/providers.sample.json when MAILLOG_PATH is
 *                      unset (the sample uses example.com-style domains), none otherwise.
 *   MAILLOG_TIMEZONE   Zone for syslog timestamps that carry none. Default UTC.
 */
final class Config
{
    public function __construct(
        public readonly string $logPath,
        public readonly ?string $providerMapFile,
        public readonly string $timezone = 'UTC',
        public readonly bool $usingSample = false,
    ) {
    }

    /**
     * @param array<string, string|false> $env
     */
    public static function fromEnvironment(?array $env = null): self
    {
        $get = static function (string $key) use ($env): ?string {
            $v = null === $env ? getenv($key) : ($env[$key] ?? false);

            return false === $v || '' === $v ? null : (string) $v;
        };

        $root = \dirname(__DIR__);
        $logPath = $get('MAILLOG_PATH');
        $usingSample = null === $logPath;
        $logPath ??= $root.'/examples/logs';

        $mapFile = $get('PROVIDER_MAP_FILE');
        if (null === $mapFile && $usingSample) {
            $mapFile = $root.'/examples/providers.sample.json';
        }

        return new self($logPath, $mapFile, $get('MAILLOG_TIMEZONE') ?? 'UTC', $usingSample);
    }
}
