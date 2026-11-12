<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\QrCode;
use PHPUnit\Framework\TestCase;

final class QrCodeTest extends TestCase
{
    /** In dark mode the text colour is white — the code must not follow it. */
    public function test_the_modules_stay_black_whatever_the_theme(): void
    {
        $svg = (string) QrCode::svg('https://fahoba.test/?pair=ABCD2345');

        $this->assertStringContainsString('fill="#000"', $svg);
        $this->assertStringContainsString('fill="#fff"', $svg);
        $this->assertStringNotContainsString('currentColor', $svg);
    }
}
