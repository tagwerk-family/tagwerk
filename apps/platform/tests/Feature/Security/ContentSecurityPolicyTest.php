<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Integrations\Plugins\PluginCatalog;
use App\Integrations\Plugins\PluginManifest;
use App\Models\Household;
use App\Models\Person;
use App\Models\User;
use App\Security\ContentSecurityPolicy;
use Fahoba\PluginApi\Plugins\Catalog;
use Fahoba\PluginApi\Plugins\LoadReport;
use Fahoba\PluginApi\Security\ContributesToCsp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class ContentSecurityPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MapSources::$sources = ['img-src' => ['https://tiles.example.org']];
    }

    public function test_every_laravel_page_carries_the_policy_reporting_only_by_default(): void
    {
        $response = $this->get('/admin/login')->assertOk();

        $policy = (string) $response->headers->get('Content-Security-Policy-Report-Only');
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("frame-ancestors 'self'", $policy);
        $this->assertStringContainsString('report-uri /api/csp-report', $policy);
        $this->assertFalse($response->headers->has('Content-Security-Policy'));

        $this->getJson('/api/does-not-exist')->assertNotFound()
            ->assertHeader('Content-Security-Policy-Report-Only');
    }

    public function test_enforced_and_off(): void
    {
        config(['fahoba.csp' => 'enforce']);
        $this->get('/admin/login')->assertHeader('Content-Security-Policy');

        config(['fahoba.csp' => 'off']);
        $response = $this->get('/admin/login');
        $this->assertFalse($response->headers->has('Content-Security-Policy'));
        $this->assertFalse($response->headers->has('Content-Security-Policy-Report-Only'));
    }

    /** Font and user menu picture come from here: nobody else learns who opens the admin. */
    public function test_the_admin_loads_nothing_from_other_hosts(): void
    {
        $household = Household::factory()->create();
        $person = Person::factory()->for($household)->create(['name' => 'Wilhelmine', 'color' => '#2563eb']);
        $parent = User::factory()->create(['household_id' => $household->id, 'person_id' => $person->id]);
        $operator = User::factory()->create(['household_id' => null, 'person_id' => null]);
        $operator->forceFill(['is_operator' => true])->save();

        foreach ([$parent, $operator] as $user) {
            $response = $this->actingAs($user)->get('/admin')->assertOk();
            $page = (string) $response->getContent();

            $this->assertStringNotContainsString('ui-avatars.com', $page);
            $this->assertStringNotContainsString('fonts.bunny.net', $page);
            $this->assertStringContainsString('data:image/svg+xml;base64,', $page);
            $policy = (string) $response->headers->get('Content-Security-Policy-Report-Only');
            $this->assertStringNotContainsString('ui-avatars.com', $policy);
            $this->assertStringNotContainsString('fonts.bunny.net', $policy);
        }
    }

    /** The app runs no inline script; only the admin needs Filament's exceptions. */
    public function test_the_app_allows_scripts_from_here_only(): void
    {
        $policy = app(ContentSecurityPolicy::class);

        $this->assertStringContainsString("script-src 'self';", $policy->forApp());
        $this->assertStringNotContainsString('unsafe', explode(';', explode('script-src', $policy->forApp())[1])[0]);
        $this->assertStringContainsString("script-src 'self' 'unsafe-inline' 'unsafe-eval'", $policy->forAdmin());
    }

    public function test_nginx_gets_the_app_policy_as_one_line(): void
    {
        $this->artisan('fahoba:csp')
            ->expectsOutputToContain('add_header Content-Security-Policy-Report-Only "default-src \'self\'; script-src \'self\';')
            ->assertSuccessful();
    }

    public function test_a_plugin_adds_its_hosts(): void
    {
        app(Catalog::class)->offer('csp', 'acme/map', MapSources::class);
        $policy = app(ContentSecurityPolicy::class);

        // Other installed plugins may add their hosts in between.
        $this->assertMatchesRegularExpression("/img-src 'self' data: blob:[^;]* https:\\/\\/tiles\\.example\\.org[;\\s]/", $policy->forApp());
        $this->assertTrue($policy->allowsImage('https://tiles.example.org/1/2/3.png'));
        $this->assertFalse($policy->allowsImage('https://tiles.example.org.evil.test/x.png'));
        $this->assertFalse($policy->allowsImage('https://other.example.org/x.png'));
    }

    public function test_a_wildcard_covers_subdomains_only(): void
    {
        MapSources::$sources = ['img-src' => ['https://*.example.org']];
        app(Catalog::class)->offer('csp', 'acme/map', MapSources::class);
        $policy = app(ContentSecurityPolicy::class);

        $this->assertTrue($policy->allowsImage('https://a.example.org/x.png'));
        $this->assertFalse($policy->allowsImage('https://badexample.org/x.png'));
    }

    public function test_a_plugin_cannot_open_scripts_or_plain_http(): void
    {
        app(Catalog::class)->offer('csp', 'acme/map', MapSources::class);

        foreach ([
            ['script-src' => ['https://cdn.example.org']],
            ['img-src' => ['http://tiles.example.org']],
            ['img-src' => ['*']],
            ['img-src' => ["https://a.example.org 'unsafe-inline'"]],
        ] as $sources) {
            MapSources::$sources = $sources;

            // Left out and reported — a plugin's mistake must not take the app down.
            $header = app(ContentSecurityPolicy::class)->forApp();
            $this->assertStringNotContainsString(array_values($sources)[0][0], $header, json_encode($sources).' was accepted.');
            $this->assertTrue(app(LoadReport::class)->failed(PluginCatalog::unowned('acme/map')));
        }
    }

    /** `extra.fahoba.csp` in a plugin's composer.json, without a class. */
    public function test_a_plugin_declares_its_hosts_in_composer_json(): void
    {
        $this->manifest(['img-src' => ['https://cdn.example.net'], 'connect-src' => ['https://api.example.net']]);
        $policy = app(ContentSecurityPolicy::class);

        $this->assertMatchesRegularExpression('/img-src [^;]*https:\/\/cdn\.example\.net/', $policy->forApp());
        $this->assertMatchesRegularExpression('/connect-src [^;]*https:\/\/api\.example\.net/', $policy->forApp());
        $this->assertTrue($policy->allowsImage('https://cdn.example.net/a.png'));
    }

    public function test_a_declared_script_host_fails_only_that_plugin(): void
    {
        $this->manifest(['script-src' => ['https://cdn.example.net'], 'img-src' => ['https://cdn.example.net']]);

        $this->assertStringNotContainsString('cdn.example.net', app(ContentSecurityPolicy::class)->forApp());
        $this->assertTrue(app(LoadReport::class)->failed('acme/cdn'));
    }

    public function test_a_plugin_that_did_not_load_declares_nothing(): void
    {
        $this->manifest(['img-src' => ['https://cdn.example.net']]);
        app(LoadReport::class)->fail('acme/cdn', 'Broken.');

        $this->assertStringNotContainsString('cdn.example.net', app(ContentSecurityPolicy::class)->forApp());
    }

    /** @param  array<string, list<string>>  $csp */
    private function manifest(array $csp): void
    {
        $directory = storage_path('framework/testing/csp-'.uniqid());
        File::ensureDirectoryExists($directory);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($directory));
        file_put_contents($directory.'/installed.json', json_encode(['packages' => [[
            'name' => 'acme/cdn',
            'version' => '1.0.0',
            'extra' => ['fahoba' => ['csp' => $csp]],
        ]]]));

        $this->app->instance(PluginManifest::class, new PluginManifest($directory.'/installed.json', $directory.'/cache.php'));
    }

    public function test_reports_are_logged_in_short(): void
    {
        Log::spy();

        $this->call('POST', '/api/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], json_encode([
            'csp-report' => [
                'document-uri' => 'https://fahoba.test/',
                'effective-directive' => 'img-src',
                'blocked-uri' => 'https://evil.test/'.str_repeat('x', 1000),
                'original-policy' => 'not kept',
            ],
        ]))->assertNoContent();

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context['effective-directive'] === 'img-src'
            && mb_strlen($context['blocked-uri']) === 300
            && ! isset($context['original-policy']));
    }

    public function test_reports_are_throttled(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/csp-report', [])->assertNoContent();
        }

        $this->postJson('/api/csp-report', [])->assertTooManyRequests();
    }
}

final class MapSources implements ContributesToCsp
{
    /** @var array<string, list<string>> */
    public static array $sources = [];

    public function sources(): array
    {
        return self::$sources;
    }
}
