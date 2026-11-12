<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\Households\Pages\EditHousehold;
use App\Models\Household;
use App\Models\User;
use App\Services\DevicePairingService;
use App\Support\Designs;
use App\Support\HouseholdContext;
use Fahoba\PluginApi\Plugins\Catalog;
use Fahoba\PluginApi\Plugins\LoadReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Fixtures\Themes\FixtureTheme;
use Tests\TestCase;

/**
 * Design packs: the household picks one, a tablet or account may pick its
 * own, and the stylesheet comes from the server with a hash in its address.
 */
final class DesignTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'fahoba/example-theme';

    private Household $household;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        app(HouseholdContext::class)->setId((int) $this->household->id);

        $device = app(DevicePairingService::class)->createCode($this->household, 'Flur');
        $this->token = app(DevicePairingService::class)->redeem($device->pairing_code)['token'];
    }

    public function test_fahobas_own_design_until_the_household_picks_one(): void
    {
        $this->withToken($this->token)->getJson('/api/theme')
            ->assertOk()
            ->assertJsonPath('design', null)
            ->assertJsonPath('designs', [['key' => self::KEY, 'name' => 'Fjord']]);

        $this->household->update(['design' => self::KEY]);

        $url = $this->withToken($this->token)->getJson('/api/theme')
            ->assertJsonPath('design.key', self::KEY)
            ->assertJsonPath('design.name', 'Fjord')
            ->json('design.url');

        $this->assertMatchesRegularExpression('#^/api/design/fahoba/example-theme/[0-9a-f]{12}\.css$#', $url);
    }

    public function test_a_device_chooses_its_own_or_fahobas(): void
    {
        $this->household->update(['design' => self::KEY]);

        $this->withToken($this->token)->putJson('/api/preferences', ['design' => 'core'])->assertOk();
        $this->withToken($this->token)->getJson('/api/theme')->assertJsonPath('design', null);

        $this->household->update(['design' => null]);
        $this->withToken($this->token)->putJson('/api/preferences', ['design' => self::KEY])->assertOk();
        $this->withToken($this->token)->getJson('/api/theme')->assertJsonPath('design.key', self::KEY);
    }

    public function test_only_installed_designs_can_be_chosen(): void
    {
        $this->withToken($this->token)->putJson('/api/preferences', ['design' => 'acme/missing'])->assertUnprocessable();
    }

    /** A package removed later must not leave the household without a design. */
    public function test_a_removed_design_falls_back_to_fahobas(): void
    {
        $this->household->update(['design' => 'acme/gone']);

        $this->withToken($this->token)->getJson('/api/theme')->assertJsonPath('design', null);
    }

    public function test_the_stylesheet_is_served_for_good(): void
    {
        $design = app(Designs::class)->describe(self::KEY);

        $this->get($design['url'])
            ->assertOk()
            ->assertHeader('Content-Type', 'text/css; charset=utf-8')
            ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public')
            ->assertSee(':root:where([data-design]){', false);

        $this->get('/api/design/fahoba/example-theme/000000000000.css')->assertNotFound();
    }

    public function test_only_files_the_stylesheet_names_are_served(): void
    {
        FixtureTheme::$css = "@font-face { font-family: Hand; src: url(hand.woff2); }\n"
            .":root[data-design] { --font-body: Hand; }\n:root[data-design][data-theme=\"dark\"] { --accent: #7cc6dc; }\n";
        app(Catalog::class)->offer('themes', 'acme/probe', FixtureTheme::class);

        $design = app(Designs::class)->get('acme/probe');
        $base = "/api/design/acme/probe/{$design['hash']}";

        $this->get("{$base}/hand.woff2")->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get("{$base}/theme.css")->assertNotFound();
        $this->get('/api/design/acme/probe/000000000000/hand.woff2')->assertNotFound();
    }

    public function test_a_design_that_breaks_the_rules_withdraws_its_package(): void
    {
        FixtureTheme::$css = '.card { color: red; }';
        app(Catalog::class)->offer('themes', 'acme/probe', FixtureTheme::class);

        $this->assertNull(app(Designs::class)->get('acme/probe'));
        $this->assertNotSame([], app(LoadReport::class)->failures());
        $this->assertNotNull(app(Designs::class)->get(self::KEY));
    }

    public function test_the_household_picks_in_the_admin(): void
    {
        $this->actingAs(User::factory()->create(['household_id' => $this->household->id]));

        Livewire::test(EditHousehold::class, ['record' => $this->household->getRouteKey()])
            ->fillForm(['design' => 'acme/unknown'])
            ->call('save')
            ->assertHasFormErrors(['design']);

        Livewire::test(EditHousehold::class, ['record' => $this->household->getRouteKey()])
            ->fillForm(['design' => self::KEY])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(self::KEY, $this->household->refresh()->design);
    }
}
