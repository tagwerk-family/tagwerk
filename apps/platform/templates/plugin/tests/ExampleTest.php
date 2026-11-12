<?php

declare(strict_types=1);

namespace Acme\Example\Tests;

use PHPUnit\Framework\TestCase;
use Acme\Example\ExamplePlugin;
use Acme\Example\ExampleWidget;
use Fahoba\PluginApi\Testing\PluginAssertions;

final class ExampleTest extends TestCase
{
    use PluginAssertions;

    public function test_its_key_is_a_plugin_key(): void
    {
        $this->assertPluginKey(ExamplePlugin::KEY);
    }

    public function test_the_widget_works(): void
    {
        $this->assertWidgetWorks(new ExampleWidget);
    }
}
