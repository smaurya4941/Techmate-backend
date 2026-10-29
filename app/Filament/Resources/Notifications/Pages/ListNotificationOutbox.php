<?php

namespace App\Filament\Resources\Notifications\Pages;

use App\Filament\Resources\Notifications\NotificationOutboxResource;
use Filament\Resources\Pages\ListRecords;

class ListNotificationOutbox extends ListRecords
{
    protected static string $resource = NotificationOutboxResource::class;
}
