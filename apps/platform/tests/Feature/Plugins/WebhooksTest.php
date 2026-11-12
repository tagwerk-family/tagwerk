<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Models\Checklist;
use App\Models\Household;
use App\Models\ListItem;
use App\Models\Person;
use App\Models\User;
use App\Support\HouseholdContext;
use App\Support\PublicAddressResolver;
use Fahoba\Webhooks\DeliverWebhookJob;
use Fahoba\Webhooks\Filament\ManageWebhooks;
use Fahoba\Webhooks\Webhook;
use Fahoba\Webhooks\WebhookDelivery;
use Fahoba\Webhooks\WebhooksArea;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\FakeResolver;
use Tests\TestCase;

/**
 * Chosen events go out signed to the household's targets; a target that is
 * down costs the delivery, never the action that caused it.
 */
final class WebhooksTest extends TestCase
{
    use RefreshDatabase;

    private Household $household;

    private Checklist $list;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(PublicAddressResolver::class, new FakeResolver([
            'hooks.example.test' => ['93.184.216.34'],
            'home.example.test' => ['192.168.1.20'],
        ]));

        $this->household = Household::factory()->create();
        $this->optIn($this->household, WebhooksArea::KEY);
        app(HouseholdContext::class)->setId((int) $this->household->id);
        $this->list = Checklist::factory()->create(['household_id' => $this->household->id]);
    }

    private function webhook(array $events = ['list_item.added'], string $url = 'https://hooks.example.test/fahoba'): Webhook
    {
        $webhook = Webhook::query()->create(['household_id' => $this->household->id, 'name' => 'HA', 'url' => $url, 'events' => $events]);
        $webhook->renewSecret();

        return $webhook;
    }

    private function addItem(string $title = 'Milch'): ListItem
    {
        return ListItem::factory()->create(['household_id' => $this->household->id, 'list_id' => $this->list->id, 'title' => $title, 'done_at' => null]);
    }

    public function test_a_chosen_event_goes_out_signed(): void
    {
        Http::fake(['hooks.example.test/*' => Http::response('', 204)]);
        $webhook = $this->webhook();

        $this->addItem();

        Http::assertSent(function (Request $request) use ($webhook): bool {
            $body = json_decode($request->body(), true);

            return $request->url() === 'https://hooks.example.test/fahoba'
                && $request->header('X-Fahoba-Event')[0] === 'list_item.added'
                && $request->header('X-Fahoba-Signature')[0] === 'sha256='.hash_hmac('sha256', $request->body(), (string) $webhook->fresh()->secret)
                && $body['data']['title'] === 'Milch'
                && $body['household_id'] === $this->household->id;
        });
        $this->assertSame(WebhookDelivery::DELIVERED, WebhookDelivery::query()->sole()->status);
    }

    public function test_other_events_stay_home(): void
    {
        Http::fake();
        $this->webhook(['task.completed']);

        $this->addItem();

        Http::assertNothingSent();
    }

    public function test_a_target_that_is_down_costs_nothing_but_the_delivery(): void
    {
        Http::fake(['hooks.example.test/*' => Http::response('boom', 500)]);
        $this->webhook();

        $this->addItem();

        $this->assertDatabaseHas('list_items', ['title' => 'Milch']);
        $delivery = WebhookDelivery::query()->sole();
        $this->assertSame(500, $delivery->response_code);
        // The sync queue has no later attempt: failed, not pending forever.
        $this->assertSame(WebhookDelivery::FAILED, $delivery->status);
    }

    public function test_a_queued_failure_is_retried(): void
    {
        Http::fake(['hooks.example.test/*' => Http::sequence()->push('boom', 500)->push('', 204)]);
        config(['queue.default' => 'database']);
        $this->webhook();

        $this->addItem();
        // Until empty: the core's announcement job waits in the same queue.
        // A worker stops after any job once the process uses more than
        // --memory (128 MB by default) — and the test process does, after
        // OpenApiTest has analysed every route. Then the delivery waits.
        $worker = ['--stop-when-empty' => true, '--sleep' => 0, '--memory' => 1024];
        $this->artisan('queue:work', $worker);
        $this->assertSame(WebhookDelivery::PENDING, WebhookDelivery::query()->sole()->status);

        $this->travel(DeliverWebhookJob::BACKOFF[0] + 1)->seconds();
        $this->artisan('queue:work', $worker);

        $delivery = WebhookDelivery::query()->sole();
        $this->assertSame(WebhookDelivery::DELIVERED, $delivery->status);
        $this->assertSame(2, $delivery->attempts);
    }

    public function test_an_address_in_a_home_network_is_never_called(): void
    {
        Http::fake();
        $this->webhook(url: 'http://home.example.test:8123/api/webhook/x');

        $this->addItem();

        Http::assertNothingSent();
        $this->assertSame(__('Not reachable or not a public address.'), WebhookDelivery::query()->sole()->error);
    }

    public function test_switched_off_nothing_is_sent(): void
    {
        Http::fake();
        $this->webhook();
        $this->household->update(['opted_in_features' => []]);

        $this->addItem();

        Http::assertNothingSent();
    }

    public function test_only_the_last_deliveries_are_kept(): void
    {
        Http::fake(['hooks.example.test/*' => Http::response('', 204)]);
        $this->webhook();

        for ($i = 0; $i < WebhookDelivery::KEEP + 3; $i++) {
            $this->addItem("Eintrag {$i}");
        }

        $this->assertSame(WebhookDelivery::KEEP, WebhookDelivery::query()->count());
    }

    public function test_the_admin_refuses_a_home_network_address(): void
    {
        $this->actingAs(User::factory()->create([
            'household_id' => $this->household->id,
            'person_id' => Person::factory()->create(['household_id' => $this->household->id])->id,
        ]));

        Livewire::test(ManageWebhooks::class)
            ->callAction(TestAction::make('create'), [
                'name' => 'HA',
                'url' => 'http://home.example.test:8123/api/webhook/x',
                'events' => ['list_item.added'],
            ])
            ->assertHasFormErrors(['url']);

        Livewire::test(ManageWebhooks::class)
            ->callAction(TestAction::make('create'), [
                'name' => 'HA',
                'url' => 'https://hooks.example.test/fahoba',
                'events' => ['list_item.added'],
            ])
            ->assertHasNoFormErrors()
            ->assertNotified(__('Copy the signing secret now — it is not shown again.'));

        $this->assertNotNull(Webhook::query()->sole()->secret);
    }
}
