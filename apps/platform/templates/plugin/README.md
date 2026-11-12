# acme/example

A [Fahoba](https://github.com/fahoba/fahoba) plugin.

## Install

In the Fahoba checkout (`apps/platform`):

```bash
composer require acme/example
```

The package lives in `plugins/`, which Composer reads as a path repository.
Fahoba loads it through `extra.fahoba.provider`; a plugin that fails is
left out and shown under Operations → Plugins.

## Licence

Choose one and put it in `LICENSE` and `composer.json` (`license`). This
plugin builds only on `fahoba/plugin-api` (MIT), so the choice is yours.

## Tests

```bash
composer install && vendor/bin/phpunit
```

The tests use `Fahoba\PluginApi\Testing\PluginAssertions` and run without
a Fahoba checkout, so the package can live in its own repository;
`.github/workflows/tests.yml` runs them there on every push. `AGENTS.md`
holds the rules for anyone, person or coding assistant, changing it.
