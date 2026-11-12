<?php

declare(strict_types=1);

namespace Tests\Feature\Plugins;

use App\Models\Household;
use App\Models\User;
use App\Support\HouseholdContext;
use App\Support\Locale;
use Fahoba\PluginApi\Plugins\Catalog;
use Fahoba\PluginApi\Plugins\LoadReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\Fixtures\Languages\Italian;
use Tests\TestCase;

/**
 * A language from a package: offered like a provider, used like `de` and
 * `en`, English wherever the pack has nothing or something broken.
 */
final class LanguagePackTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_installed_pack_is_a_supported_language(): void
    {
        $this->offerItalian();

        $this->assertSame(['de' => 'Deutsch', 'en' => 'English', 'fr' => 'Français', 'it' => 'Italiano'], Locale::supported());
        $this->assertSame('it-IT', Locale::intlTag('it'));
        $this->assertSame('de-AT', Locale::intlTag('de'));
    }

    public function test_the_server_speaks_the_pack_and_falls_back_to_english(): void
    {
        $this->offerItalian();

        Locale::using('it', function (): void {
            $this->assertSame('Salva', __('Save'));
            $this->assertSame('Ciao Lena,', __('Hello :name,', ['name' => 'Lena']));
            // Not in the pack: English, the key itself.
            $this->assertSame('Cancel', __('Cancel'));
            // A broken placeholder would swallow the name; English shows it.
            $this->assertSame('Gift to Lena', __('Gift to :name', ['name' => 'Lena']));
        });
    }

    public function test_validation_messages_come_from_the_pack_or_laravels_english(): void
    {
        $this->offerItalian();

        Locale::using('it', function (): void {
            $errors = Validator::make([], ['name' => 'required', 'mail' => 'nullable|email'])->errors();
            $this->assertSame('Il campo name è obbligatorio.', $errors->first('name'));

            $errors = Validator::make(['mail' => 'x'], ['mail' => 'email'])->errors();
            $this->assertSame('The mail field must be a valid email address.', $errors->first('mail'));
        });
    }

    public function test_the_pack_never_changes_the_built_in_languages(): void
    {
        $this->offerItalian();

        Locale::using('de', fn () => $this->assertSame('Speichern', __('Save')));
    }

    public function test_an_account_chooses_the_pack(): void
    {
        $this->offerItalian();
        $household = Household::factory()->create(['locale' => 'de']);
        app(HouseholdContext::class)->setId((int) $household->id);
        $user = User::factory()->create(['household_id' => $household->id]);
        $this->withToken($user->createToken('test')->plainTextToken);

        $this->putJson('/api/auth/locale', ['locale' => 'it'])
            ->assertOk()
            ->assertJsonPath('locale', 'it');

        $this->getJson('/api/auth/me')->assertJsonPath('locales.it', 'Italiano');
    }

    public function test_a_removed_pack_leaves_the_choice_but_not_the_language(): void
    {
        $household = Household::factory()->create(['locale' => 'de']);
        $user = User::factory()->create(['household_id' => $household->id, 'locale' => 'it']);

        $this->assertSame('de', Locale::for($user));
        $this->assertSame('it', $user->fresh()->locale);
    }

    public function test_a_pack_may_not_offer_a_built_in_code(): void
    {
        $this->assertRefused(BuiltInCode::class);
    }

    public function test_a_second_pack_for_the_same_code_is_refused(): void
    {
        $this->offerItalian();
        $this->offer('acme/other-italian', SecondItalian::class);

        Locale::supported();

        $this->assertFalse(app(LoadReport::class)->failed('acme/italian'));
        $this->assertTrue(app(LoadReport::class)->failed('acme/other-italian'));
    }

    public function test_a_pack_needs_a_valid_tag_and_its_files(): void
    {
        $this->assertRefused(BadTag::class);
        $this->assertRefused(MissingFiles::class, 'acme/missing');
    }

    private function offerItalian(): void
    {
        $this->offer('acme/italian', Italian::class);
    }

    private function offer(string $package, string $class): void
    {
        app(Catalog::class)->during($package, static fn () => app(Catalog::class)->offer('languages', $package, $class));
    }

    private function assertRefused(string $class, string $package = 'acme/broken'): void
    {
        $this->offer($package, $class);

        $this->assertSame(['de', 'en', 'fr'], array_keys(Locale::supported()));
        $this->assertTrue(app(LoadReport::class)->failed($package));
    }
}

class BuiltInCode extends Italian
{
    public function code(): string
    {
        return 'de';
    }

    public function intlTag(): string
    {
        return 'de-CH';
    }
}

class SecondItalian extends Italian {}

class BadTag extends Italian
{
    public function intlTag(): string
    {
        return 'french';
    }
}

class MissingFiles extends Italian
{
    public function code(): string
    {
        return 'es';
    }

    public function intlTag(): string
    {
        return 'es-ES';
    }

    public function backendPath(): string
    {
        return '/nowhere';
    }
}
