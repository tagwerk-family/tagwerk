<?php

declare(strict_types=1);

namespace Fahoba\Mcp;

use Fahoba\Mcp\Tools\AddListItem;
use Fahoba\Mcp\Tools\CompleteListItem;
use Fahoba\Mcp\Tools\CompleteTask;
use Fahoba\Mcp\Tools\CreateEvent;
use Fahoba\Mcp\Tools\ListEvents;
use Fahoba\Mcp\Tools\ListLists;
use Fahoba\Mcp\Tools\ListTasks;
use Fahoba\Mcp\Tools\MealPlan;
use Fahoba\Mcp\Tools\TaskStatistics;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Fahoba')]
#[Version('1.0.0')]
#[Instructions('A family calendar: events, tasks with stars for children, lists and a meal plan of one household. Dates are calendar days in the household\'s time zone. Look ids up with the list tools before writing. A read-only token offers no writing tools.')]
final class FahobaServer extends Server
{
    protected array $tools = [
        ListEvents::class,
        CreateEvent::class,
        ListTasks::class,
        CompleteTask::class,
        TaskStatistics::class,
        ListLists::class,
        AddListItem::class,
        CompleteListItem::class,
        MealPlan::class,
    ];
}
