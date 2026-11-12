<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Models\DashboardWidget;
use App\Models\Household;
use App\Services\DevicePairingService;
use App\Support\HouseholdContext;
use Fahoba\PluginApi\Dashboard\DataWidget;
use Fahoba\PluginApi\Dashboard\HasSettings;
use Fahoba\PluginApi\Dashboard\SettingField;
use Fahoba\PluginApi\Dashboard\WidgetContent;
use Fahoba\PluginApi\Dashboard\WidgetContext;
use Fahoba\PluginApi\Plugins\Catalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** A plugin widget's settings: a form from its fields, checked and stored by the server. */
final class PluginWidgetSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        StationWidget::$seen = null;
        $this->household = Household::factory()->create();
        app(HouseholdContext::class)->setId((int) $this->household->id);

        $code = app(DevicePairingService::class)->createCode($this->household, 'Küche');
        ['token' => $this->token] = app(DevicePairingService::class)->redeem($code->pairing_code);

        app(Catalog::class)->offer('widgets', 'acme/station', StationWidget::class);
    }

    private function create(array $settings): TestResponse
    {
        return $this->withToken($this->token)->postJson('/api/wall/dashboard', [
            'type' => 'acme/station', 'x' => 0, 'y' => 20, 'settings' => $settings,
        ]);
    }

    public function test_the_fields_are_offered_with_the_widget(): void
    {
        $offer = collect($this->withToken($this->token)->getJson('/api/wall/dashboard')->json('available'))->keyBy('type');

        $this->assertSame(['station', 'unit', 'live', 'key', 'height'], array_column($offer['acme/station']['settings'], 'key'));
        $this->assertSame([['value' => 'c', 'label' => 'Celsius'], ['value' => 'f', 'label' => 'Fahrenheit']], $offer['acme/station']['settings'][1]['options']);
        $this->assertSame([], $offer['clock']['settings']);
    }

    public function test_what_does_not_fit_the_fields_is_refused(): void
    {
        $this->create(['unit' => 'k', 'key' => 'abc'])->assertUnprocessable()
            ->assertJsonValidationErrors(['settings.station', 'settings.unit']);
    }

    /** The key never travels back to a device: only whether it is set. */
    public function test_a_secret_is_stored_encrypted_and_never_shown(): void
    {
        $id = $this->create(['station' => 'Wien', 'unit' => 'c', 'live' => '1', 'key' => 'abc', 'stray' => 'x'])->assertCreated()->json('widget.id');

        $stored = DashboardWidget::query()->findOrFail($id)->settings;
        $this->assertNotSame('abc', $stored['key']);
        $this->assertSame('abc', Crypt::decryptString($stored['key']));
        $this->assertTrue($stored['live']);
        $this->assertArrayNotHasKey('stray', $stored);

        $tiles = collect($this->withToken($this->token)->getJson('/api/wall/dashboard')->json('widgets'))->keyBy('id');
        $this->assertTrue($tiles[$id]['settings']['key']);
        $this->assertStringNotContainsString('abc', (string) json_encode($tiles[$id]));
    }

    /** PHP reads 1e400 as INF, which JSON cannot carry: refused, not a 500. */
    public function test_a_number_beyond_any_float_is_refused(): void
    {
        $this->create(['station' => 'Wien', 'key' => 'abc', 'height' => '1e400'])->assertUnprocessable()
            ->assertJsonValidationErrors(['settings.height']);
        $this->create(['station' => 'Wien', 'key' => 'abc', 'height' => '1e3'])->assertCreated()
            ->assertJsonPath('widget.settings.height', 1000);
    }

    public function test_an_empty_secret_keeps_the_stored_one(): void
    {
        $id = $this->create(['station' => 'Wien', 'key' => 'abc'])->json('widget.id');

        $this->withToken($this->token)->patchJson("/api/wall/dashboard/{$id}", [
            'x' => 0, 'y' => 20, 'w' => 4, 'h' => 3, 'settings' => ['station' => 'Graz', 'key' => ''],
        ])->assertOk();

        $stored = DashboardWidget::query()->findOrFail($id)->settings;
        $this->assertSame('Graz', $stored['station']);
        $this->assertSame('abc', Crypt::decryptString($stored['key']));
    }

    /** Moving a tile sends no settings; a new tile from the picker has none yet. */
    public function test_placing_leaves_the_settings_alone(): void
    {
        $empty = $this->withToken($this->token)->postJson('/api/wall/dashboard', ['type' => 'acme/station', 'x' => 4, 'y' => 20])->assertCreated();
        $id = $this->create(['station' => 'Wien', 'key' => 'abc'])->json('widget.id');

        $moved = $this->withToken($this->token)->patchJson("/api/wall/dashboard/{$id}", ['x' => 8, 'y' => 20, 'w' => 4, 'h' => 3])->assertOk();

        $this->assertNull($empty->json('widget.settings'));
        $this->assertTrue($moved->json('widget.settings.key'));
        $this->assertSame('abc', Crypt::decryptString(DashboardWidget::query()->findOrFail($id)->settings['key']));
    }

    public function test_the_widget_receives_the_secret_in_plain(): void
    {
        $id = $this->create(['station' => 'Wien', 'key' => 'abc'])->json('widget.id');

        $this->withToken($this->token)->getJson("/api/wall/dashboard/{$id}/content")->assertOk();

        $this->assertSame('abc', StationWidget::$seen['key'] ?? null);
        $this->assertSame('Wien', StationWidget::$seen['station'] ?? null);
    }
}

final class StationWidget implements DataWidget, HasSettings
{
    /** @var array<string, mixed>|null */
    public static ?array $seen = null;

    public function label(): string
    {
        return 'Wetterstation';
    }

    public function description(): string
    {
        return '';
    }

    public function area(): ?string
    {
        return null;
    }

    public function settings(): array
    {
        return [
            SettingField::text('station', 'Station', required: true),
            SettingField::select('unit', 'Einheit', ['c' => 'Celsius', 'f' => 'Fahrenheit']),
            SettingField::toggle('live', 'Live'),
            SettingField::secret('key', 'API-Schlüssel', required: true),
            SettingField::number('height', 'Höhe'),
        ];
    }

    public function content(WidgetContext $context, array $settings): WidgetContent
    {
        self::$seen = $settings;

        return WidgetContent::metric('12°');
    }
}
