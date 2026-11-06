<?php

declare(strict_types=1);

namespace MailcowDeliverability\Log;

/**
 * A Postfix log on disk: one file, or a directory of rotated files.
 *
 * Directory mode reads every file whose name looks like a mail log
 * (mail.log, mail.log.1, mail.log.2.gz, maillog-20261101, *.log, *.gz),
 * oldest rotation first, so lines come out in chronological order.
 * Files ending in .gz are decompressed on the fly.
 */
final class LogSource
{
    private const NAME_RE = '/^(mail|maillog|postfix)|\.log(\.\d+)?(\.gz)?$|\.gz$/i';

    public function __construct(private readonly string $path)
    {
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isDirectory(): bool
    {
        return is_dir($this->path);
    }

    /**
     * Files that will be read, in reading order (oldest first).
     *
     * @return list<string>
     */
    public function files(): array
    {
        if (is_file($this->path)) {
            return [$this->path];
        }
        if (!is_dir($this->path)) {
            throw new \RuntimeException(sprintf('Log path "%s" does not exist. Set MAILLOG_PATH to a Postfix log file or a directory of rotated logs.', $this->path));
        }

        $files = [];
        foreach (scandir($this->path) ?: [] as $name) {
            if ('.' === $name[0]) {
                continue;
            }
            $full = $this->path.\DIRECTORY_SEPARATOR.$name;
            if (is_file($full) && preg_match(self::NAME_RE, $name)) {
                $files[] = $full;
            }
        }

        usort($files, static fn (string $a, string $b): int => self::age($b) <=> self::age($a) ?: strcmp($a, $b));

        return $files;
    }

    /**
     * Rotation age used for ordering: mail.log = 0, mail.log.1 = 1, mail.log.2.gz = 2.
     * Date-suffixed names (maillog-20261101) sort by date, newest = lowest age.
     */
    private static function age(string $file): int
    {
        $name = basename($file);
        if (preg_match('/\.(\d{1,3})(?:\.gz)?$/', $name, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/(\d{8})(?:\.gz)?$/', $name, $m)) {
            return 99999999 - (int) $m[1];
        }

        return 0;
    }

    /**
     * @return \Generator<int, string>
     */
    public function lines(): \Generator
    {
        foreach ($this->files() as $file) {
            yield from self::readFile($file);
        }
    }

    /**
     * @return \Generator<int, string>
     */
    public static function readFile(string $file): \Generator
    {
        $gz = str_ends_with(strtolower($file), '.gz');
        $h = $gz ? gzopen($file, 'rb') : fopen($file, 'rb');
        if (false === $h) {
            throw new \RuntimeException(sprintf('Cannot open log file "%s".', $file));
        }
        try {
            while (true) {
                $line = $gz ? gzgets($h) : fgets($h);
                if (false === $line) {
                    break;
                }
                yield rtrim($line, "\r\n");
            }
        } finally {
            $gz ? gzclose($h) : fclose($h);
        }
    }
}
