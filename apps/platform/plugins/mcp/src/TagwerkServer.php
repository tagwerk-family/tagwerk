<?php

declare(strict_types=1);

namespace Tagwerk\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Tagwerk\Mcp\Tools\AddListItem;
use Tagwerk\Mcp\Tools\CompleteListItem;
use Tagwerk\Mcp\Tools\CompleteTask;
use Tagwerk\Mcp\Tools\CreateEvent;
use Tagwerk\Mcp\Tools\ListEvents;
use Tagwerk\Mcp\Tools\ListLists;
use Tagwerk\Mcp\Tools\ListTasks;
use Tagwerk\Mcp\Tools\MealPlan;
use Tagwerk\Mcp\Tools\TaskStatistics;

#[Name('Tagwerk')]
#[Version('1.0.0')]
#[Instructions('A family calendar: events, tasks with stars for children, lists and a meal plan of one household. Dates are calendar days in the household\'s time zone. Look ids up with the list tools before writing. A read-only token offers no writing tools.')]
final class TagwerkServer extends Server
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
