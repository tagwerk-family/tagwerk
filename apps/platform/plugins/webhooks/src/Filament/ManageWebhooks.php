<?php

declare(strict_types=1);

namespace Fahoba\Webhooks\Filament;

use Fahoba\Webhooks\Webhook;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageWebhooks extends ManageRecords
{
    protected static string $resource = WebhookResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->after(fn (Webhook $record) => WebhookResource::announce($record->renewSecret())),
        ];
    }
}
