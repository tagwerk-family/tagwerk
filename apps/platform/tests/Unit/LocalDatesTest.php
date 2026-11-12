<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\LocalDates;
use App\Support\Locale;
use Carbon\CarbonImmutable;
use Fahoba\PluginApi\Plugins\Catalog;
use Tests\Fixtures\Languages\Italian;
use Tests\TestCase;

/**
 * German and English keep their formats exactly; a pack's language gets
 * ICU's patterns for its tag without bringing formats of its own.
 */
final class LocalDatesTest extends TestCase
{
    private CarbonImmutable $day;

    protected function setUp(): void
    {
        parent::setUp();

        $this->day = CarbonImmutable::parse('2026-01-15 14:05', 'Europe/Vienna');
    }

    public function test_german_stays_as_it_was(): void
    {
        Locale::using('de', function (): void {
            $this->assertSame('d.m.Y', LocalDates::phpDate());
            $this->assertSame('d.m.Y H:i', LocalDates::phpDateTime());
            $this->assertSame('15. Januar', LocalDates::dayMonth($this->day));
            $this->assertSame('Do 15. Jan', LocalDates::weekdayDayMonth($this->day));
        });
    }

    public function test_english_stays_as_it_was(): void
    {
        Locale::using('en', function (): void {
            $this->assertSame('M j, Y', LocalDates::phpDate());
            $this->assertSame('M j, Y g:i A', LocalDates::phpDateTime());
            $this->assertSame('January 15', LocalDates::dayMonth($this->day));
            $this->assertSame('Thu, Jan 15', LocalDates::weekdayDayMonth($this->day));
        });
    }

    public function test_a_pack_language_formats_by_its_tag(): void
    {
        app(Catalog::class)->during('acme/italian', static fn () => app(Catalog::class)->offer('languages', 'acme/italian', Italian::class));

        Locale::using('it', function (): void {
            $this->assertSame('d/m/Y', LocalDates::phpDate());
            $this->assertSame('15/01/2026, 14:05', $this->day->format(LocalDates::phpDateTime()));
            $this->assertSame('15 gennaio', LocalDates::dayMonth($this->day));
            $this->assertSame('gio 15 gen', LocalDates::weekdayDayMonth($this->day));
        });
    }
}
