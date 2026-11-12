<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Enums\ProviderArea;
use App\Integrations\Plugins\PluginCatalog;
use App\Integrations\ProviderRegistry;
use Fahoba\PluginApi\Calendar\CalendarProvider;
use Fahoba\PluginApi\Plugins\Catalog;
use Fahoba\PluginApi\Plugins\LoadReport;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * Providers from packages: offered at boot, active as soon as installed.
 * An invalid offer withdraws its package and is logged — the app boots.
 */
final class PluginCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CountingCalendar::$created = 0;
    }

    private function catalog(): PluginCatalog
    {
        return app(PluginCatalog::class);
    }

    public function test_an_installed_plugin_sits_next_to_the_built_in_providers(): void
    {
        app(Catalog::class)->offer('calendar', 'acme/calendar', CountingCalendar::class);

        $providers = app(ProviderRegistry::class)->providers(ProviderArea::Calendar);

        $this->assertInstanceOf(CountingCalendar::class, $providers['acme/calendar']);
        $this->assertArrayHasKey('builtin', $providers);
    }

    public function test_a_key_without_vendor_prefix_is_rejected(): void
    {
        // Otherwise a package could take over the builtin Google provider including its tokens.
        $this->assertRejected('calendar', 'google', CountingCalendar::class);
    }

    public function test_a_duplicate_key_fails_the_second_package_only(): void
    {
        $this->offerAs('acme/first', 'calendar', 'acme/calendar', CountingCalendar::class);
        $this->offerAs('acme/second', 'calendar', 'acme/calendar', OtherCalendar::class);

        $this->assertSame(CountingCalendar::class, $this->catalog()->offered('calendar')['acme/calendar'] ?? null);
        $this->assertFalse(app(LoadReport::class)->failed('acme/first'));
        $this->assertTrue(app(LoadReport::class)->failed('acme/second'));
    }

    public function test_an_unknown_class_is_rejected(): void
    {
        $this->assertRejected('calendar', 'acme/calendar', 'Acme\DoesNotExist');
    }

    public function test_a_class_without_the_area_contract_is_rejected(): void
    {
        $this->assertRejected('photos', 'acme/calendar', CountingCalendar::class);
    }

    public function test_an_unknown_area_is_rejected(): void
    {
        $this->assertRejected('magic', 'acme/calendar', CountingCalendar::class);
    }

    public function test_a_third_party_cannot_claim_a_fahoba_key(): void
    {
        $this->assertRejected('calendar', 'fahoba/calendar', CountingCalendar::class, 'acme/evil');

        config(['fahoba.first_party' => ['fahoba/calendar']]);
        $this->offerAs('fahoba/calendar', 'calendar', 'fahoba/calendar', CountingCalendar::class);
        $this->assertArrayHasKey('fahoba/calendar', $this->catalog()->offered('calendar'));
    }

    /** A late callback offers without a package; its failure must not hit the key's owner. */
    public function test_an_offer_outside_a_plugin_never_withdraws_the_owner_of_its_key(): void
    {
        $this->offerAs('acme/calendar', 'calendar', 'acme/calendar', CountingCalendar::class);
        app(Catalog::class)->offer('calendar', 'acme/calendar', OtherCalendar::class);

        $this->assertSame(CountingCalendar::class, $this->catalog()->offered('calendar')['acme/calendar'] ?? null);
        $this->assertFalse(app(LoadReport::class)->failed('acme/calendar'));
        $this->assertNotSame([], app(LoadReport::class)->failures());
    }

    public function test_an_offer_outside_a_plugin_cannot_claim_a_fahoba_key(): void
    {
        app(Catalog::class)->offer('calendar', 'fahoba/calendar', CountingCalendar::class);

        $this->assertArrayNotHasKey('fahoba/calendar', $this->catalog()->offered('calendar'));
    }

    public function test_one_invalid_offer_withdraws_everything_its_package_offered(): void
    {
        $this->offerAs('acme/x', 'calendar', 'acme/good', CountingCalendar::class);
        $this->offerAs('acme/x', 'magic', 'acme/bad', CountingCalendar::class);

        $this->assertArrayNotHasKey('acme/good', $this->catalog()->offered('calendar'));
    }

    public function test_the_same_failure_is_logged_once_an_hour(): void
    {
        Log::spy();

        app(LoadReport::class)->fail('acme/x', 'broken');
        app(LoadReport::class)->fail('acme/x', 'broken');
        app(LoadReport::class)->fail('acme/x', 'broken differently');

        Log::shouldHaveReceived('error')->with('Plugin acme/x not loaded: broken', Mockery::any())->once();
        Log::shouldHaveReceived('error')->with('Plugin acme/x not loaded: broken differently', Mockery::any())->once();
    }

    private function offerAs(string $package, string $area, string $key, string $class): void
    {
        app(Catalog::class)->during($package, static fn () => app(Catalog::class)->offer($area, $key, $class));
    }

    private function assertRejected(string $area, string $key, string $class, string $package = 'acme/x'): void
    {
        Log::spy();

        $this->offerAs($package, $area, $key, $class);

        $this->assertArrayNotHasKey($key, $this->catalog()->offered($area));
        $this->assertTrue(app(LoadReport::class)->failed($package));
        Log::shouldHaveReceived('error')->withArgs(
            static fn (string $message): bool => str_starts_with($message, "Plugin {$package} not loaded: "),
        )->once();
    }

    public function test_the_registry_creates_only_what_is_asked_and_only_once(): void
    {
        app(Catalog::class)->offer('calendar', 'acme/calendar', CountingCalendar::class);

        $registry = app(ProviderRegistry::class);

        $registry->calendarProvider('builtin');
        $this->assertSame(0, CountingCalendar::$created);

        $first = $registry->calendarProvider('acme/calendar');
        $second = $registry->calendarProvider('acme/calendar');

        $this->assertSame($first, $second);
        $this->assertSame(1, CountingCalendar::$created);
    }
}

final class CountingCalendar implements CalendarProvider
{
    public static int $created = 0;

    public function __construct()
    {
        self::$created++;
    }

    public function key(): string
    {
        return 'acme/calendar';
    }

    public function label(): string
    {
        return 'Acme Calendar';
    }
}

final class OtherCalendar implements CalendarProvider
{
    public function key(): string
    {
        return 'acme/calendar';
    }

    public function label(): string
    {
        return 'Other Calendar';
    }
}
