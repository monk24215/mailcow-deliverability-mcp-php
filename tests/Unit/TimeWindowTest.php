<?php

declare(strict_types=1);

namespace MailcowDeliverability\Tests\Unit;

use MailcowDeliverability\Analysis\TimeWindow;
use MailcowDeliverability\Log\DeliveryEvent;
use PHPUnit\Framework\TestCase;

final class TimeWindowTest extends TestCase
{
    /**
     * @return list<DeliveryEvent>
     */
    private static function events(): array
    {
        $mk = static fn (?string $t, string $q): DeliveryEvent => new DeliveryEvent(null === $t ? null : new \DateTimeImmutable($t), $q, "$q@example.com", 'example.com', 'example.com', 'sent', '2.0.0', '250 OK', '');

        return [
            $mk('2026-11-01T10:00:00+00:00', 'a'),
            $mk('2026-11-02T10:00:00+00:00', 'b'),
            $mk('2026-11-03T09:00:00+00:00', 'c'),
            $mk('2026-11-03T10:00:00+00:00', 'd'),
            $mk(null, 'e'),
        ];
    }

    public function testParse(): void
    {
        self::assertSame(86400, TimeWindow::parse('24h')->seconds);
        self::assertSame(604800, TimeWindow::parse('7d')->seconds);
        self::assertSame(5400, TimeWindow::parse('90m')->seconds);
        self::assertSame(1209600, TimeWindow::parse('2w')->seconds);
        self::assertNull(TimeWindow::parse('all')->seconds);
    }

    public function testRejectsGarbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        TimeWindow::parse('yesterday');
    }

    public function testAnchorsOnLatestEvent(): void
    {
        $w = TimeWindow::parse('24h')->apply(self::events());
        // The window is (from, to]: an event exactly 24h before the newest one is outside it.
        self::assertSame(['c', 'd'], array_map(static fn ($e) => $e->queueId, $w['events']));
        self::assertSame('2026-11-03T10:00:00+00:00', $w['to']?->format(\DATE_ATOM));
        self::assertSame('2026-11-02T10:00:00+00:00', $w['from']?->format(\DATE_ATOM));
    }

    public function testAllKeepsUntimedEvents(): void
    {
        self::assertCount(5, TimeWindow::parse('all')->apply(self::events())['events']);
    }

    public function testAnchorNow(): void
    {
        $w = TimeWindow::parse('2h')->apply(self::events(), 'now', new \DateTimeImmutable('2026-11-03T11:00:00+00:00'));
        self::assertSame(['d'], array_map(static fn ($e) => $e->queueId, $w['events']));
    }
}
