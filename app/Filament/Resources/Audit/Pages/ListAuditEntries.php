<?php

namespace App\Filament\Resources\Audit\Pages;

use App\Filament\Resources\Audit\AuditEntryResource;
use Filament\Resources\Pages\ListRecords;

class ListAuditEntries extends ListRecords
{
    protected static string $resource = AuditEntryResource::class;
}
