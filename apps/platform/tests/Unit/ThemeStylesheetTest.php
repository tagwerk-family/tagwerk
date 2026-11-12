<?php

declare(strict_types=1);

namespace Tests\Unit;

use Fahoba\ExampleTheme\Fjord;
use Fahoba\PluginApi\Testing\PluginAssertions;
use Fahoba\PluginApi\Themes\Stylesheet;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\Themes\FixtureTheme;
use Tests\TestCase;

/**
 * A design sets tokens and nothing else: anything that styles components
 * would break with the next release, anything that loads from elsewhere
 * would leave the server's policy.
 */
final class ThemeStylesheetTest extends TestCase
{
    use PluginAssertions;

    private const BASE = ":root[data-design] { --accent: #0a6680; }\n:root[data-design][data-theme=\"dark\"] { --accent: #7cc6dc; }\n";

    protected function setUp(): void
    {
        parent::setUp();
        FixtureTheme::$name = 'Probe';
    }

    public function test_the_example_design_passes(): void
    {
        $this->assertThemeValid(new Fjord);
    }

    public function test_fonts_and_pictures_beside_the_file_are_served_from_the_design(): void
    {
        FixtureTheme::$css = "@font-face { font-family: Hand; src: url(hand.woff2) format('woff2'); }\n"
            .self::BASE
            .":root[data-design] { --font-body: Hand, sans-serif; }\n";

        $sheet = Stylesheet::read((new FixtureTheme)->stylesheet());
        $this->assertIsArray($sheet);

        $css = Stylesheet::serve($sheet, '/api/design/acme/probe/0123456789ab');

        $this->assertStringContainsString('url("/api/design/acme/probe/0123456789ab/hand.woff2")', $css);
        $this->assertStringContainsString(':root:where([data-design]){--accent:#0a6680}', $css);
        $this->assertStringContainsString(':root[data-theme="dark"]:where([data-design]){--accent:#7cc6dc}', $css);
        $this->assertSame(['hand.woff2' => FixtureTheme::folder().'/hand.woff2'], $sheet['assets']);
    }

    /** @return array<string, array{string}> */
    public static function refused(): array
    {
        return [
            'a class' => [self::BASE.'.card { --accent: red; }'],
            'an element property' => [self::BASE.':root[data-design] { color: red; }'],
            'an unknown token' => [self::BASE.':root[data-design] { --anything: 1px; }'],
            'only light' => [':root[data-design] { --accent: #0a6680; }'],
            'an import' => ["@import url(x.css);\n".self::BASE],
            'a media query' => [self::BASE.'@media print { :root[data-design] { --accent: #000; } }'],
            'a file from elsewhere' => [self::BASE.':root[data-design] { --backdrop: url(https://example.org/a.png); }'],
            'a path out of the folder' => [self::BASE.':root[data-design] { --backdrop: url(../../etc/passwd.png); }'],
            'an svg' => [self::BASE.':root[data-design] { --backdrop: url(hand.svg); }'],
            'javascript' => [self::BASE.':root[data-design] { --backdrop: url(javascript:alert(1)); }'],
            'important' => [self::BASE.':root[data-design] { --accent: #000 !important; }'],
            'an escape' => [self::BASE.':root[data-design] { --accent: "\\75 rl(x)"; }'],
            'an unknown attribute' => [self::BASE.':root[data-design][data-entry] { --accent: #000; }'],
            'unreadable text' => [":root[data-design] { --ink: #eeeeee; }\n:root[data-design][data-theme=\"dark\"] { --accent: #7cc6dc; }"],
            'unreadable dark text' => [":root[data-design] { --accent: #0a6680; }\n:root[data-design][data-theme=\"dark\"] { --ink: #222222; }"],
        ];
    }

    #[DataProvider('refused')]
    public function test_a_stylesheet_is_refused_for(string $css): void
    {
        FixtureTheme::$css = $css;

        $this->assertNotNull(Stylesheet::problem(new FixtureTheme));
    }

    public function test_a_design_needs_a_name(): void
    {
        FixtureTheme::$css = self::BASE;
        FixtureTheme::$name = ' ';

        $this->assertSame('A theme needs a name.', Stylesheet::problem(new FixtureTheme));
    }

    public function test_contrast_follows_wcag(): void
    {
        $this->assertEqualsWithDelta(21.0, Stylesheet::contrast('#000', '#ffffff'), 0.01);
        $this->assertNull(Stylesheet::contrast('rgb(0 0 0)', '#fff'));
    }
}
