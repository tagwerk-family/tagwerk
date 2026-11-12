<?php

declare(strict_types=1);

namespace Tests\Feature\Lists;

use App\Enums\ProviderArea;
use App\Integrations\Lists\GoogleTasks\GoogleTasksListProvider;
use App\Models\Checklist;
use App\Models\Household;
use App\Models\ProviderAccount;
use App\Services\Lists\ListImportService;
use App\Support\HouseholdContext;
use Fahoba\PluginApi\Lists\ListItemDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Google Tasks is a service of its own, not an appendage of the calendar.
 *
 * This is the proof of the split: the same provider, two areas, two grants —
 * and neither brings the other along.
 */
final class GoogleTasksTest extends TestCase
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

    private function connection(): ProviderAccount
    {
        return ProviderAccount::factory()->create([
            'household_id' => $this->household->id,
            'area' => ProviderArea::Lists,
            'provider' => 'google',
            'account_label' => 'alex@example.test',
            'access_token' => 'ya29.valid',
            'token_expires_at' => now()->addHour(),
        ]);
    }

    public function test_the_list_grant_carries_no_calendar_scopes(): void
    {
        $url = app(GoogleTasksListProvider::class)->authorizationUrl(
            'state-123',
            'https://fahoba.test/auth/lists/google/callback',
        );

        $this->assertStringContainsString('auth%2Ftasks', $url);
        $this->assertStringNotContainsString('calendar', $url);
        $this->assertStringNotContainsString('include_granted_scopes', $url);
    }

    public function test_calendar_and_lists_are_two_connections(): void
    {
        $calendar = ProviderAccount::factory()->create([
            'household_id' => $this->household->id,
            'area' => ProviderArea::Calendar,
            'provider' => 'google',
            'account_label' => 'alex@example.test',
        ]);

        $lists = $this->connection();

        $this->assertNotSame($calendar->id, $lists->id);

        // The same account, the same service — separated by area.
        $this->assertSame(
            1,
            ProviderAccount::query()->forArea(ProviderArea::Lists)->count(),
        );
    }

    public function test_task_lists_are_remembered_when_connecting(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'tasks.googleapis.com/tasks/v1/users/@me/lists*' => Http::response([
                'items' => [
                    ['id' => 'MTIz', 'title' => 'Einkauf'],
                    ['id' => 'NDU2', 'title' => 'Baumarkt'],
                ],
            ]),
        ]);

        $connection = $this->connection();

        app(ListImportService::class)->finish($connection);

        $this->assertSame(
            [
                ['id' => 'MTIz', 'name' => 'Einkauf'],
                ['id' => 'NDU2', 'name' => 'Baumarkt'],
            ],
            $connection->fresh()->settings['targets'],
        );
    }

    public function test_an_item_lands_in_the_chosen_task_list(): void
    {
        Http::preventStrayRequests();
        Http::fake(['tasks.googleapis.com/*' => Http::response(['id' => 'new'])]);

        app(GoogleTasksListProvider::class)->push(
            [new ListItemDraft('Milch', '1 Liter')],
            $this->connection(),
            'MTIz',
        );

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://tasks.googleapis.com/tasks/v1/lists/MTIz/tasks'
                && $request['title'] === 'Milch'
                && $request['notes'] === '1 Liter';
        });
    }

    public function test_a_list_can_point_to_google_while_the_calendar_does_not(): void
    {
        $lists = $this->connection();

        $list = Checklist::factory()->create([
            'household_id' => $this->household->id,
            'list_provider' => 'google',
            'list_account_id' => $lists->id,
            'list_target' => 'MTIz',
        ]);

        $this->assertSame($lists->id, $list->account->id);

        // No calendar account for the same service — the lists stand alone.
        $this->assertSame(
            0,
            ProviderAccount::query()
                ->forArea(ProviderArea::Calendar)
                ->where('provider', 'google')
                ->count(),
        );
    }
}
