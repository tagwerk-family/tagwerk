<?php

declare(strict_types=1);
use Fahoba\PluginApi\Recipes\Ingredient;

// The class moved to the plugin API. A released migration still imports the
// old name, and migrations are sealed (MigrationsAppendOnlyTest) — so the old
// name stays resolvable for it, and only for it.
class_alias(Ingredient::class, 'App\Support\Recipes\Ingredient');
