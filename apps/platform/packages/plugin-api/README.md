# fahoba/plugin-api

The contracts, data objects and helpers a [Fahoba](https://github.com/fahoba/fahoba)
plugin talks to: providers, dashboard widgets, areas, extensions, photo rules.

MIT licensed — a plugin built only on this package may carry any licence its
author chooses. Fahoba itself is under PolyForm Shield 1.0.0.

Start with the guide, [Writing a Fahoba plugin](https://github.com/fahoba/fahoba/blob/master/docs/plugins.md),
or let a Fahoba checkout write a package for you:

```bash
php artisan fahoba:make-plugin acme/waste --widget
```

Or start from the [template repository](https://github.com/fahoba/fahoba-plugin-template)
(“Use this template”), which needs no Fahoba checkout.

Test your plugin in its own repository with the testkit:

```php
final class WasteTest extends \PHPUnit\Framework\TestCase
{
    use \Fahoba\PluginApi\Testing\PluginAssertions;

    public function test_the_widget_works(): void
    {
        $this->assertWidgetWorks(new WasteWidget, ['street' => 'Hauptstraße']);
    }
}
```

`0.x` until plugins outside the monorepo have been built against it; from
`1.0.0` on SemVer — see *What stays stable* in the guide.

This repository is a read-only split of the Fahoba monorepo, made on every
release. Issues and pull requests go to the main repository.
