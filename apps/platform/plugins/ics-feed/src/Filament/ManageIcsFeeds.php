<?php

declare(strict_types=1);

namespace Fahoba\IcsFeed\Filament;

use Fahoba\IcsFeed\IcsFeed;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageIcsFeeds extends ManageRecords
{
    protected static string $resource = IcsFeedResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->after(fn (IcsFeed $record) => IcsFeedResource::announce($record->renew())),
        ];
    }
}
