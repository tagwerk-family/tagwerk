<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The way into an empty server: an operator without a household.
 */
final class CreateOperatorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_an_operator_without_a_household(): void
    {
        $this->artisan('fahoba:create-operator', ['name' => 'Betrieb', 'email' => 'betrieb@example.test'])
            ->expectsQuestion('Password', 'long-enough-12')
            ->assertSuccessful();

        $user = User::query()->where('email', 'betrieb@example.test')->firstOrFail();
        $this->assertTrue($user->is_operator);
        $this->assertFalse($user->memberships()->exists());
        $this->assertSame(0, Household::query()->count());
    }

    public function test_refuses_a_short_password_and_a_taken_address(): void
    {
        $this->artisan('fahoba:create-operator', ['name' => 'Betrieb', 'email' => 'betrieb@example.test'])
            ->expectsQuestion('Password', 'too-short')
            ->assertFailed();

        User::factory()->create(['email' => 'taken@example.test']);

        $this->artisan('fahoba:create-operator', ['name' => 'Betrieb', 'email' => 'taken@example.test'])
            ->expectsQuestion('Password', 'long-enough-12')
            ->assertFailed();

        $this->assertSame(0, User::query()->where('is_operator', true)->count());
    }
}
