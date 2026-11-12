<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PhoneAppWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_points_to_the_phone_app(): void
    {
        $this->actingAs(User::factory()->create(['household_id' => Household::factory()->create()->id]))
            ->get('/admin')
            ->assertSuccessful()
            ->assertSee('Fahoba aufs Handy')
            ->assertSee(url('/'));
    }

    public function test_the_operator_without_household_does_not_see_it(): void
    {
        $operator = User::factory()->create(['household_id' => null]);
        $operator->forceFill(['is_operator' => true])->save();

        $this->actingAs($operator)->get('/admin')->assertSuccessful()->assertDontSee('Fahoba aufs Handy');
    }
}
