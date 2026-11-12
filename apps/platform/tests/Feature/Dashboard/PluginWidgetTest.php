<?php

declare(strict_types=1);

namespace Tests\Feature\Dashboard;

use App\Models\DashboardWidget;
use App\Models\Device;
use App\Models\Household;
use App\Models\Person;
use App\Models\User;
use App\Services\DevicePairingService;
use App\Support\HouseholdContext;
use Fahoba\PluginApi\Dashboard\DataWidget;
use Fahoba\PluginApi\Dashboard\HandlesActions;
use Fahoba\PluginApi\Dashboard\ImagePolicy;
use Fahoba\PluginApi\Dashboard\WidgetContent;
use Fahoba\PluginApi\Dashboard\WidgetContext;
use Fahoba\PluginApi\Plugins\Catalog;
use Fahoba\PluginApi\Security\ContributesToCsp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * Widgets from packages: the plugin delivers data, the wall renders it.
 */
final class PluginWidgetTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private string $token;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();

        WasteWidget::$calls = 0;
        WasteWidget::$broken = false;
        WasteWidget::$area = null;
        WasteWidget::$context = null;
        WasteWidget::$taps = [];

        $this->household = Household::factory()->create();
        app(HouseholdContext::class)->setId((int) $this->household->id);

        $device = app(DevicePairingService::class)->createCode($this->household, 'Küche');
        ['device' => $this->device, 'token' => $this->token] = app(DevicePairingService::class)->redeem($device->pairing_code);

        app(Catalog::class)->offer('widgets', 'acme/waste', WasteWidget::class);
    }

    private function tile(): DashboardWidget
    {
        return DashboardWidget::factory()->create([
            'household_id' => $this->household->id,
            'device_id' => $this->device->id,
            'type' => 'acme/waste',
            'x' => 0,
            'y' => 0,
            'w' => 4,
            'h' => 3,
        ]);
    }

    public function test_it_is_offered_and_rendered_generically(): void
    {

        $offered = collect($this->withToken($this->token)->getJson('/api/wall/dashboard')->json('available'))
            ->keyBy('type');

        $this->assertSame('Müllabfuhr', $offered['acme/waste']['label']);
        $this->assertTrue($offered['acme/waste']['generic']);
        $this->assertFalse($offered['clock']['generic']);
    }

    public function test_the_tile_gets_its_content_from_the_plugin(): void
    {
        $tile = $this->tile();

        $this->withToken($this->token)
            ->getJson("/api/wall/dashboard/{$tile->id}/content")
            ->assertOk()
            ->assertJsonPath('content.kind', 'list')
            ->assertJsonPath('content.rows.0.text', 'Restmüll')
            ->assertJsonPath('content.rows.0.detail', 'Morgen');
    }

    /** Several tablets in the house must not multiply the load on the plugin's source. */
    public function test_the_content_is_kept_for_a_minute(): void
    {
        $tile = $this->tile();

        $this->withToken($this->token)->getJson("/api/wall/dashboard/{$tile->id}/content")->assertOk();
        $this->withToken($this->token)->getJson("/api/wall/dashboard/{$tile->id}/content")->assertOk();

        $this->assertSame(1, WasteWidget::$calls);
    }

    /** The wall shows a sentence there; the error ends up in the log. */
    public function test_an_error_in_the_plugin_stays_in_its_tile(): void
    {
        WasteWidget::$broken = true;
        $tile = $this->tile();

        $this->withToken($this->token)
            ->getJson("/api/wall/dashboard/{$tile->id}/content")
            ->assertStatus(503)
            ->assertJsonPath('message', 'Gerade nicht verfügbar.');
    }

    /** Builtin widgets fetch their own data — there is no content there. */
    public function test_builtin_tiles_have_no_content_endpoint(): void
    {
        $clock = DashboardWidget::factory()->create([
            'household_id' => $this->household->id,
            'device_id' => $this->device->id,
            'type' => 'clock',
            'x' => 0,
            'y' => 0,
        ]);

        $this->withToken($this->token)->getJson("/api/wall/dashboard/{$clock->id}/content")->assertNotFound();
    }

    public function test_a_widget_of_a_disabled_area_disappears(): void
    {
        WasteWidget::$area = 'meals';
        $this->household->update(['disabled_features' => ['meals']]);
        $tile = $this->tile();

        $this->withToken($this->token)->getJson("/api/wall/dashboard/{$tile->id}/content")->assertNotFound();
    }

    public function test_the_widget_learns_about_the_household_without_the_model(): void
    {
        $this->household->update(['timezone' => 'Europe/Berlin', 'locale' => 'en', 'disabled_features' => ['meals']]);
        $tile = $this->tile();

        $this->withToken($this->token)->getJson("/api/wall/dashboard/{$tile->id}/content")->assertOk();

        $context = WasteWidget::$context;
        $this->assertNotNull($context);
        $this->assertSame((int) $this->household->id, $context->householdId);
        $this->assertSame('Europe/Berlin', $context->timezone);
        $this->assertSame('en', $context->locale);
        $this->assertTrue($context->has('calendar'));
        $this->assertFalse($context->has('meals'));
    }

    public function test_more_shapes_stay_data(): void
    {
        $table = WidgetContent::table(['Tonne', 'Tag'], [['Restmüll', 'Mo', 'extra'], ['Papier']])->toArray();
        $this->assertSame([['Restmüll', 'Mo'], ['Papier', '']], $table['rows']);

        $this->assertSame(10.0, WidgetContent::progress(12, 10, 'Gelesen')->toArray()['value']);
        $this->assertSame([['label' => 'Pegel', 'value' => '3.5']], WidgetContent::pairs([['label' => 'Pegel', 'value' => 3.5]])->toArray()['rows']);
        $this->assertCount(6, WidgetContent::actions(array_fill(0, 9, ['name' => 'go', 'label' => 'Los']))->toArray()['buttons']);

        $this->expectException(InvalidArgumentException::class);
        WidgetContent::actions([['name' => 'Go Now', 'label' => 'Los']]);
    }

    public function test_a_tap_runs_the_action_and_answers_with_new_content(): void
    {
        $tile = $this->tile();

        $this->withToken($this->token)
            ->postJson("/api/wall/dashboard/{$tile->id}/action/empty")
            ->assertOk()
            ->assertJsonPath('content.kind', 'metric')
            ->assertJsonPath('content.value', 'empty');

        $this->assertSame(['empty'], WasteWidget::$taps);

        // The next fetch shows what the action answered, not the minute-old content.
        $this->withToken($this->token)->getJson("/api/wall/dashboard/{$tile->id}/content")->assertJsonPath('content.value', 'empty');
    }

    /** A child passing by the hallway tablet must not do what a parent decides. */
    public function test_a_guarded_action_asks_for_the_pin_on_a_tablet(): void
    {
        $this->parentWithPin($this->household);
        $tile = $this->tile();

        $this->withToken($this->token)->postJson("/api/wall/dashboard/{$tile->id}/action/order")->assertStatus(423);
        $this->withToken($this->token)->postJson("/api/wall/dashboard/{$tile->id}/action/empty")->assertOk();

        $this->assertSame(['empty'], WasteWidget::$taps);
    }

    /** On the phone a child's own account must not do it either. */
    public function test_a_guarded_action_needs_household_management_on_an_account(): void
    {
        $child = Person::factory()->child()->create(['household_id' => $this->household->id]);
        $account = User::factory()->create(['household_id' => $this->household->id, 'person_id' => $child->id]);
        $parent = $this->parentWithPin($this->household);
        $tile = fn (User $owner): DashboardWidget => DashboardWidget::factory()->create([
            'household_id' => $this->household->id, 'user_id' => $owner->id, 'device_id' => null,
            'type' => 'acme/waste', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 3,
        ]);

        $own = $tile($account);
        $this->actingAs($account)->postJson("/api/wall/dashboard/{$own->id}/action/order")->assertForbidden();
        $this->actingAs($account)->postJson("/api/wall/dashboard/{$own->id}/action/empty")->assertOk();

        $theirs = $tile($parent);
        $this->actingAs($parent)->postJson("/api/wall/dashboard/{$theirs->id}/action/order")->assertOk();

        $this->assertSame(['empty', 'order'], WasteWidget::$taps);
    }

    /** Every tap runs plugin code that may call a service; a stuck finger or a script must not hammer it. */
    public function test_taps_are_throttled_per_device(): void
    {
        $tile = $this->tile();

        for ($i = 0; $i < 30; $i++) {
            $this->withToken($this->token)->postJson("/api/wall/dashboard/{$tile->id}/action/empty")->assertOk();
        }

        $this->withToken($this->token)->postJson("/api/wall/dashboard/{$tile->id}/action/empty")->assertTooManyRequests();
    }

    public function test_a_widget_without_actions_has_none(): void
    {
        app(Catalog::class)->offer('widgets', 'acme/plain', PlainWidget::class);
        $tile = $this->tile();
        $tile->update(['type' => 'acme/plain']);

        $this->withToken($this->token)->postJson("/api/wall/dashboard/{$tile->id}/action/empty")->assertNotFound();
    }

    public function test_an_error_in_an_action_stays_in_its_tile(): void
    {
        WasteWidget::$broken = true;
        $tile = $this->tile();

        $this->withToken($this->token)->postJson("/api/wall/dashboard/{$tile->id}/action/empty")->assertStatus(503);
    }

    /** Outside Fahoba (a plugin's own unit test) there is no policy: only paths pass. */
    public function test_without_a_policy_only_paths_pass(): void
    {
        $this->app->offsetUnset(ImagePolicy::class);

        $this->assertSame('/a.jpg', WidgetContent::image('/a.jpg')->toArray()['url']);
        $this->expectException(InvalidArgumentException::class);
        WidgetContent::image('https://example.test/a.jpg');
    }

    /**
     * A plugin cannot send a script to the wall through an image, nor an
     * image the Content Security Policy would block.
     */
    public function test_images_only_from_declared_hosts_or_from_here(): void
    {
        app(Catalog::class)->offer('csp', 'acme/waste', WasteSources::class);

        $this->assertSame('/storage/a.jpg', WidgetContent::image('/storage/a.jpg')->toArray()['url']);
        $this->assertSame('https://example.test/a.jpg', WidgetContent::image('https://example.test/a.jpg')->toArray()['url']);

        foreach (['javascript:alert(1)', '//example.test/a.jpg', 'http://example.test/a.jpg', 'file:///etc/passwd', 'https://elsewhere.test/a.jpg'] as $url) {
            try {
                WidgetContent::image($url);
                $this->fail("{$url} was accepted.");
            } catch (InvalidArgumentException $expected) {
                $this->assertStringContainsString($url, $expected->getMessage());
            }
        }
    }
}

final class WasteSources implements ContributesToCsp
{
    public function sources(): array
    {
        return ['img-src' => ['https://example.test']];
    }
}

final class PlainWidget implements DataWidget
{
    public function label(): string
    {
        return 'Schlicht';
    }

    public function description(): string
    {
        return '';
    }

    public function area(): ?string
    {
        return null;
    }

    public function content(WidgetContext $context, array $settings): WidgetContent
    {
        return WidgetContent::metric('1');
    }
}

final class WasteWidget implements DataWidget, HandlesActions
{
    /** @var list<string> */
    public static array $taps = [];

    public static int $calls = 0;

    public static bool $broken = false;

    public static ?string $area = null;

    public static ?WidgetContext $context = null;

    public function label(): string
    {
        return 'Müllabfuhr';
    }

    public function description(): string
    {
        return 'Welche Tonne als Nächstes rausmuss.';
    }

    public function area(): ?string
    {
        return self::$area;
    }

    public function content(WidgetContext $context, array $settings): WidgetContent
    {
        self::$calls++;
        self::$context = $context;

        if (self::$broken) {
            throw new RuntimeException('Collection calendar unreachable.');
        }

        return WidgetContent::list([
            ['text' => 'Restmüll', 'detail' => 'Morgen'],
            ['text' => 'Papier'],
        ]);
    }

    public function act(WidgetContext $context, array $settings, string $action): WidgetContent
    {
        if (self::$broken) {
            throw new RuntimeException('Collection calendar unreachable.');
        }

        self::$taps[] = $action;

        return WidgetContent::metric($action);
    }

    public function guarded(string $action): bool
    {
        return $action === 'order';
    }
}
