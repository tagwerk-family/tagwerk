<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Models\Household;
use App\Models\Person;
use App\Models\User;
use Fahoba\PluginApi\Plugins\Catalog;
use Fahoba\PluginApi\Plugins\LoadReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Languages\Italian;
use Tests\TestCase;

/** The operator sees what is installed and why a plugin did not load. */
final class PluginsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_operator_sees_every_installed_plugin(): void
    {
        $this->actingAs($this->operator())->get('/admin/operator/plugins')
            ->assertOk()
            ->assertSee('fahoba/bring')
            ->assertSee('PolyForm-Shield-1.0.0')
            ->assertSee('Geladen');
    }

    public function test_a_plugin_that_did_not_load_shows_its_reason(): void
    {
        app(LoadReport::class)->fail('fahoba/bring', 'Cannot reach the service.');

        $this->actingAs($this->operator())->get('/admin/operator/plugins')
            ->assertOk()
            ->assertSee('Nicht geladen')
            ->assertSee('Cannot reach the service.');
    }

    public function test_a_language_pack_shows_what_falls_back_to_english(): void
    {
        app(Catalog::class)->during('acme/italian', static fn () => app(Catalog::class)->offer('languages', 'acme/italian', Italian::class));

        $this->actingAs($this->operator())->get('/admin/operator/plugins')
            ->assertOk()
            ->assertSee('Italiano (it)')
            ->assertSee('Texte erscheinen auf Englisch')
            ->assertSee('Platzhalter weichen ab')
            ->assertSee('Gift to :name')
            ->assertSee('app.common.cancel')
            ->assertDontSee('app.common.save');
    }

    public function test_a_parent_does_not_get_in(): void
    {
        $household = Household::factory()->create();
        $person = Person::factory()->for($household)->create();
        $parent = User::factory()->create(['household_id' => $household->id, 'person_id' => $person->id]);

        $this->actingAs($parent)->get('/admin/operator/plugins')->assertForbidden();
    }

    private function operator(): User
    {
        $operator = User::factory()->create(['household_id' => null, 'person_id' => null]);
        $operator->forceFill(['is_operator' => true])->save();

        return $operator;
    }
}
