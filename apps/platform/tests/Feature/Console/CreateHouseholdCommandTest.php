<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A household from the shell, without the admin panel.
 */
final class CreateHouseholdCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_household_with_its_first_parent(): void
    {
        $this->artisan('fahoba:create-household', ['name' => 'Familie Muster', 'parent' => 'Alex', 'email' => 'alex@example.test'])
            ->expectsQuestion('Password', 'long-enough-1')
            ->assertSuccessful();

        $user = User::query()->where('email', 'alex@example.test')->firstOrFail();
        $this->assertFalse($user->is_operator);
        $this->assertSame('Familie Muster', Household::query()->findOrFail($user->memberships()->sole()->household_id)->name);
    }

    public function test_refuses_a_short_password(): void
    {
        $this->artisan('fahoba:create-household', ['name' => 'Familie Muster', 'parent' => 'Alex', 'email' => 'alex@example.test'])
            ->expectsQuestion('Password', 'short')
            ->assertFailed();

        $this->assertSame(0, Household::query()->count());
    }
}
