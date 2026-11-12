<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Areas\ApiArea;
use App\Auth\ApiTokens;
use App\Filament\Pages\ApiAccess;
use App\Filament\Resources\Households\Pages\EditHousehold;
use App\Http\Middleware\ApiTokenScope;
use App\Models\Household;
use App\Models\Person;
use App\Models\User;
use App\Support\HouseholdContext;
use Fahoba\IcsFeed\IcsFeedArea;
use Fahoba\Mcp\McpArea;
use Fahoba\Webhooks\WebhooksArea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * What opens the household to the outside is off until a parent switches it
 * on — per household, and switched off again every token stops at once.
 */
final class OptInAreasTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private User $parent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        $this->parent = User::factory()->create([
            'household_id' => $this->household->id,
            'person_id' => Person::factory()->for($this->household)->create()->id,
        ]);
    }

    private function apiToken(): string
    {
        return app(ApiTokens::class)->create($this->parent, (int) $this->household->id, 'Skript', write: false);
    }

    public function test_a_new_household_has_no_interface(): void
    {
        foreach ([ApiArea::KEY, IcsFeedArea::KEY, WebhooksArea::KEY, McpArea::KEY] as $area) {
            $this->assertFalse($this->household->has($area), $area);
        }

        $this->assertContains('calendar', $this->household->features());
    }

    public function test_a_token_opens_nothing_until_api_access_is_on(): void
    {
        $token = $this->apiToken();

        $this->withToken($token)->getJson('/api/wall/lists')->assertNotFound();

        $this->optIn($this->household, ApiArea::KEY);
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/wall/lists')->assertOk();

        $this->household->update(['opted_in_features' => []]);
        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/wall/lists')->assertNotFound();
    }

    public function test_switching_api_access_on_in_one_household_opens_no_other(): void
    {
        $this->optIn(Household::factory()->create(), ApiArea::KEY);

        $this->withToken($this->apiToken())->getJson('/api/wall/lists')->assertNotFound();
    }

    public function test_the_app_sign_in_needs_no_interface(): void
    {
        $this->withToken($this->parent->createToken('mobile', ['*'])->plainTextToken)
            ->getJson('/api/wall/lists')
            ->assertOk();
    }

    public function test_mcp_needs_api_access(): void
    {
        $this->optIn($this->household, McpArea::KEY);

        $this->assertFalse($this->household->fresh()->has(McpArea::KEY));

        $this->optIn($this->household->fresh(), ApiArea::KEY);
        $this->assertTrue($this->household->fresh()->has(McpArea::KEY));
    }

    public function test_a_token_is_throttled(): void
    {
        $this->optIn($this->household, ApiArea::KEY);
        $token = $this->apiToken();

        for ($i = 0; $i < ApiTokenScope::PER_MINUTE; $i++) {
            Auth::forgetGuards();
            $this->withToken($token)->getJson('/api/session')->assertSuccessful();
        }

        Auth::forgetGuards();
        $this->withToken($token)->getJson('/api/session')->assertTooManyRequests();
    }

    public function test_the_settings_page_is_gone_while_off(): void
    {
        $this->actingAs($this->parent);
        app(HouseholdContext::class)->setId((int) $this->household->id);

        $this->assertFalse(ApiAccess::canAccess());

        $this->optIn($this->household, ApiArea::KEY);
        $this->assertTrue(ApiAccess::canAccess());
    }

    public function test_the_household_form_switches_interfaces_on_separately(): void
    {
        $this->actingAs($this->parent);
        app(HouseholdContext::class)->setId((int) $this->household->id);

        Livewire::test(EditHousehold::class, ['record' => $this->household->getRouteKey()])
            ->assertFormSet(['opted_in_features' => []])
            ->set('data.opted_in_features', [ApiArea::KEY, 'acme/unknown'])
            ->call('save')
            ->assertHasFormErrors(['opted_in_features.1'])
            ->set('data.opted_in_features', [ApiArea::KEY])
            ->call('save')
            ->assertHasNoFormErrors();

        $household = $this->household->fresh();
        $this->assertSame([ApiArea::KEY], $household->opted_in_features);
        $this->assertTrue($household->has(ApiArea::KEY));
        $this->assertTrue($household->has('calendar'));
    }
}
