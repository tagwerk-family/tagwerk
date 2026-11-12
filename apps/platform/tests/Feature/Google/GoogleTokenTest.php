<?php

declare(strict_types=1);

namespace Tests\Feature\Google;

use App\Enums\ConnectionStatus;
use App\Integrations\Calendar\Google\GoogleCalendarProvider;
use App\Integrations\Google\GoogleOAuth;
use App\Models\Household;
use App\Models\ProviderAccount;
use App\Support\HouseholdContext;
use Fahoba\PluginApi\Connections\AuthorizationLost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class GoogleTokenTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        $this->household = Household::factory()->create();
        app(HouseholdContext::class)->setId((int) $this->household->id);

        config([
            'services.google.client_id' => 'client.apps.googleusercontent.com',
            'services.google.client_secret' => 'secret',
        ]);
    }

    public function test_expired_token_is_refreshed(): void
    {
        $source = ProviderAccount::factory()->expiredToken()->create([
            'household_id' => $this->household->id,
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'ya29.fresh',
                'expires_in' => 3599,
            ]),
        ]);

        $token = app(GoogleOAuth::class)->freshAccessToken($source);

        $this->assertSame('ya29.fresh', $token);
        $this->assertTrue($source->fresh()->token_expires_at->isFuture());
    }

    public function test_valid_token_is_not_refreshed_unnecessarily(): void
    {
        $source = ProviderAccount::factory()->create([
            'household_id' => $this->household->id,
            'access_token' => 'ya29.still-valid',
        ]);

        Http::fake();

        $this->assertSame('ya29.still-valid', app(GoogleOAuth::class)->freshAccessToken($source));

        Http::assertNothingSent();
    }

    /**
     * Mandatory path 4: invalid_grant is the expected way a connection ends —
     * not an error that may be swallowed.
     */
    public function test_invalid_grant_marks_the_source(): void
    {
        $source = ProviderAccount::factory()->expiredToken()->create([
            'household_id' => $this->household->id,
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'Token has been expired or revoked.',
            ], 400),
        ]);

        try {
            app(GoogleOAuth::class)->freshAccessToken($source);
            $this->fail('AuthorizationLost was expected.');
        } catch (AuthorizationLost $exception) {
            $this->assertSame($source->id, $exception->source->id);
        }

        $fresh = $source->fresh();

        $this->assertSame(ConnectionStatus::InvalidGrant, $fresh->status);
        $this->assertNull($fresh->access_token);
    }

    public function test_marked_source_is_not_even_fetched(): void
    {
        $source = ProviderAccount::factory()->invalidGrant()->create([
            'household_id' => $this->household->id,
        ]);

        Http::fake();

        $this->expectException(AuthorizationLost::class);

        try {
            app(GoogleOAuth::class)->freshAccessToken($source);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_consent_url_requests_a_refresh_token(): void
    {
        $url = app(GoogleCalendarProvider::class)->authorizationUrl(
            'state-123',
            'https://fahoba.test/auth/calendar/google/callback',
        );

        // Without these two parameters there is no refresh token.
        $this->assertStringContainsString('access_type=offline', $url);
        $this->assertStringContainsString('prompt=consent', $url);
        $this->assertStringContainsString('state=state-123', $url);
        $this->assertStringContainsString('calendar.events', $url);

        // The calendar grant carries no scopes for other areas.
        $this->assertStringNotContainsString('include_granted_scopes', $url);
        $this->assertStringNotContainsString('tasks', $url);
    }
}
