<?php

declare(strict_types=1);

namespace MailcowDeliverability\Tests\Unit;

use MailcowDeliverability\Log\LogSource;
use PHPUnit\Framework\TestCase;

final class LogSourceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/mdl-test-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir.'/mail.log', "line-today-1\nline-today-2\n");
        file_put_contents($this->dir.'/mail.log.1', "line-yesterday\n");
        file_put_contents($this->dir.'/mail.log.2.gz', gzencode("line-two-days-ago-1\nline-two-days-ago-2\n"));
        file_put_contents($this->dir.'/mail.log.10.gz', gzencode("line-ten-days-ago\n"));
        file_put_contents($this->dir.'/README.txt', "not a log\n");
        file_put_contents($this->dir.'/.hidden.log', "hidden\n");
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->dir) ?: [] as $name) {
            if (is_file($this->dir.'/'.$name)) {
                unlink($this->dir.'/'.$name);
            }
        }
        rmdir($this->dir);
    }

    public function testDirectoryReadsRotatedFilesOldestFirst(): void
    {
        $src = new LogSource($this->dir);
        self::assertSame(['mail.log.10.gz', 'mail.log.2.gz', 'mail.log.1', 'mail.log'], array_map('basename', $src->files()));
        self::assertSame(
            ['line-ten-days-ago', 'line-two-days-ago-1', 'line-two-days-ago-2', 'line-yesterday', 'line-today-1', 'line-today-2'],
            iterator_to_array($src->lines(), false),
        );
    }

    public function testSingleFileIncludingGzip(): void
    {
        self::assertSame(['line-yesterday'], iterator_to_array((new LogSource($this->dir.'/mail.log.1'))->lines(), false));
        self::assertSame(['line-two-days-ago-1', 'line-two-days-ago-2'], iterator_to_array((new LogSource($this->dir.'/mail.log.2.gz'))->lines(), false));
    }

    public function testDateSuffixedRotation(): void
    {
        $d = $this->dir.'/dated';
        mkdir($d);
        file_put_contents($d.'/maillog', "c\n");
        file_put_contents($d.'/maillog-20261102', "b\n");
        file_put_contents($d.'/maillog-20261101.gz', gzencode("a\n"));
        self::assertSame(['a', 'b', 'c'], iterator_to_array((new LogSource($d))->lines(), false));
        array_map('unlink', glob($d.'/*') ?: []);
        rmdir($d);
    }

    public function testMissingPathThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        (new LogSource($this->dir.'/nope'))->files();
    }
}
