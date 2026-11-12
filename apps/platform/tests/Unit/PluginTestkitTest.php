<?php

declare(strict_types=1);

namespace Tests\Unit;

use Fahoba\PluginApi\Dashboard\DataWidget;
use Fahoba\PluginApi\Dashboard\HandlesActions;
use Fahoba\PluginApi\Dashboard\HasSettings;
use Fahoba\PluginApi\Dashboard\SettingField;
use Fahoba\PluginApi\Dashboard\WidgetContent;
use Fahoba\PluginApi\Dashboard\WidgetContext;
use Fahoba\PluginApi\Languages\Reference;
use Fahoba\PluginApi\Testing\PluginAssertions;
use Illuminate\Support\Arr;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\Languages\Italian;

/** The testkit a plugin author runs without a Fahoba checkout. */
final class PluginTestkitTest extends TestCase
{
    use PluginAssertions;

    public function test_a_sound_widget_passes(): void
    {
        $this->assertWidgetWorks(new KitWidget);
        $this->assertWidgetWorks(new KitWidget, ['city' => 'Wien']);
    }

    public function test_the_context_has_sensible_defaults(): void
    {
        $context = $this->widgetContext(areas: ['meals']);

        $this->assertSame('Europe/Vienna', $context->timezone);
        $this->assertTrue($context->has('meals'));
    }

    public function test_a_widget_that_throws_on_empty_settings_fails(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('empty settings');

        $this->assertWidgetWorks(new KitWidget(throwWithoutCity: true));
    }

    public function test_duplicate_setting_keys_fail(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('twice');

        $this->assertWidgetWorks(new KitWidget(fields: [SettingField::text('a', 'A'), SettingField::text('a', 'B')]));
    }

    public function test_an_unknown_action_that_runs_unguarded_fails(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('unknown action');

        $this->assertWidgetWorks(new KitWidget(guardsUnknown: false, actsOnUnknown: true));
    }

    public function test_keys_follow_the_vendor_rule(): void
    {
        $this->assertPluginKey('acme/waste');

        foreach (['waste', 'fahoba/waste', 'Acme/Waste'] as $key) {
            try {
                $this->assertPluginKey($key);
            } catch (AssertionFailedError) {
                continue;
            }
            $this->fail("{$key} passed");
        }
    }

    public function test_a_complete_language_pack_passes(): void
    {
        $this->assertLanguagePackComplete(new CompletePack);
    }

    public function test_an_incomplete_pack_names_every_text_that_would_show_in_english(): void
    {
        try {
            $this->assertLanguagePackComplete(new Italian);
        } catch (AssertionFailedError $failure) {
            $this->assertStringContainsString('missing: Gift from :name', $failure->getMessage());
            $this->assertStringContainsString('missing: app.common.cancel', $failure->getMessage());
            $this->assertStringContainsString('placeholders differ: Gift to :name', $failure->getMessage());
            $this->assertStringNotContainsString("\nmissing: Save\n", $failure->getMessage());

            return;
        }

        $this->fail('An incomplete pack passed.');
    }

    public function test_a_pack_for_a_built_in_language_fails(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('built-in code de');

        $this->assertLanguagePackComplete(new class extends Italian
        {
            public function code(): string
            {
                return 'de';
            }

            public function intlTag(): string
            {
                return 'de-CH';
            }
        });
    }
}

/** Every reference text "translated" as itself, written out like a real pack. */
final class CompletePack extends Italian
{
    private static ?string $directory = null;

    public function backendPath(): string
    {
        return self::directory().'/backend';
    }

    public function frontendMessages(): string
    {
        return self::directory().'/frontend.json';
    }

    private static function directory(): string
    {
        if (self::$directory !== null) {
            return self::$directory;
        }

        $directory = sys_get_temp_dir().'/fahoba-complete-pack-'.getmypid();
        $reference = Reference::load();

        if (! is_dir("{$directory}/backend/it")) {
            mkdir("{$directory}/backend/it", 0755, true);
        }

        file_put_contents("{$directory}/backend/it.json", json_encode($reference['backend']));
        file_put_contents("{$directory}/frontend.json", json_encode(Arr::undot($reference['frontend'])));

        foreach ($reference['groups'] as $group => $texts) {
            file_put_contents("{$directory}/backend/it/{$group}.php", '<?php return '.var_export(Arr::undot($texts), true).';');
        }

        return self::$directory = $directory;
    }
}

final class KitWidget implements DataWidget, HandlesActions, HasSettings
{
    /** @param list<SettingField>|null $fields */
    public function __construct(
        private bool $throwWithoutCity = false,
        private ?array $fields = null,
        private bool $guardsUnknown = true,
        private bool $actsOnUnknown = false,
    ) {}

    public function label(): string
    {
        return 'Kit';
    }

    public function description(): string
    {
        return 'A tile for the testkit.';
    }

    public function area(): ?string
    {
        return null;
    }

    public function settings(): array
    {
        return $this->fields ?? [SettingField::text('city', 'City', required: true)];
    }

    public function content(WidgetContext $context, array $settings): WidgetContent
    {
        if ($this->throwWithoutCity && ! isset($settings['city'])) {
            throw new \RuntimeException('no city');
        }

        return WidgetContent::actions([['name' => 'refresh', 'label' => 'Refresh']], $settings['city'] ?? 'Choose a city');
    }

    public function act(WidgetContext $context, array $settings, string $action): WidgetContent
    {
        if ($action !== 'refresh' && ! $this->actsOnUnknown) {
            throw new \InvalidArgumentException('unknown');
        }

        return $this->content($context, $settings);
    }

    public function guarded(string $action): bool
    {
        return $action !== 'refresh' && $this->guardsUnknown;
    }
}
