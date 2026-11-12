<?php

declare(strict_types=1);

namespace Tests\Feature\Connections;

use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The connection route carries the area in the URL.
 *
 * That way a service's calendar can be connected without also connecting its
 * lists — and a callback from one route cannot land in the other.
 */
final class ConnectionFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.client_id' => 'client.apps.googleusercontent.com']);

        $household = Household::factory()->create();

        $this->actingAs(User::factory()->create(['household_id' => $household->id]));
    }

    public function test_calendar_route_requests_only_calendar_scopes(): void
    {
        $response = $this->get('/auth/calendar/google/redirect');

        $response->assertRedirect();
        $target = (string) $response->headers->get('Location');

        $this->assertStringContainsString('calendar.events', $target);
        $this->assertStringContainsString(
            urlencode(route('connection.callback', ['area' => 'calendar', 'provider' => 'google'])),
            $target,
        );

        // Nothing from another area travels along.
        $this->assertStringNotContainsString('tasks', $target);
        $this->assertStringNotContainsString('include_granted_scopes', $target);
    }

    public function test_builtin_calendar_cannot_be_connected(): void
    {
        $this->get('/auth/calendar/builtin/redirect')->assertNotFound();
    }

    public function test_unknown_area_has_no_route(): void
    {
        $this->get('/auth/unknown/google/redirect')->assertNotFound();
    }

    public function test_list_route_requests_only_list_scopes(): void
    {
        $response = $this->get('/auth/lists/google/redirect');

        $target = (string) $response->headers->get('Location');

        $this->assertStringContainsString('auth%2Ftasks', $target);

        // The same service, a different grant: no calendar included.
        $this->assertStringNotContainsString('calendar', $target);
    }

    /**
     * After the grant one step is still missing: who do the calendars belong
     * to?
     *
     * Whoever does not find the menu item on their own ends up with nothing
     * but colourless family calendars on the wall -- so the calendar route
     * ends where the next step is.
     */
    public function test_the_calendar_route_ends_at_the_calendars(): void
    {
        $this->grantSucceeds();

        $this->get('/auth/calendar/google/redirect');
        $state = (string) session('connection_oauth_state.state');

        $this->get("/auth/calendar/google/callback?state={$state}&code=abc")
            ->assertRedirect('/admin/calendars');
    }

    /** Lists have no assignment to a person -- there it stays at the overview. */
    public function test_the_list_route_ends_at_the_overview(): void
    {
        $this->grantSucceeds();

        $this->get('/auth/lists/google/redirect');
        $state = (string) session('connection_oauth_state.state');

        $this->get("/auth/lists/google/callback?state={$state}&code=abc")
            ->assertRedirect('/admin');
    }

    private function grantSucceeds(): void
    {
        config(['services.google.client_secret' => 'secret']);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'ya29.new',
                'refresh_token' => 'refresh',
                'expires_in' => 3599,
            ]),
            'www.googleapis.com/oauth2/v3/userinfo' => Http::response(['email' => 'familie@fahoba.invalid']),
            '*' => Http::response(['items' => []]),
        ]);
    }

    public function test_callback_is_only_valid_in_its_own_area(): void
    {
        Http::preventStrayRequests();

        $this->get('/auth/calendar/google/redirect');

        $state = (string) session('connection_oauth_state.state');

        /*
         * The same state, a different area: without the check the calendar
         * token would end up in the table as a list connection.
         */
        $this->get("/auth/lists/google/callback?state={$state}&code=abc")
            ->assertRedirect('/admin')
            ->assertSessionHas('error');
    }

    public function test_forged_callback_is_rejected(): void
    {
        Http::preventStrayRequests();

        $this->get('/auth/calendar/google/redirect');

        $this->get('/auth/calendar/google/callback?state=forged&code=abc')
            ->assertRedirect('/admin')
            ->assertSessionHas('error');
    }
}
