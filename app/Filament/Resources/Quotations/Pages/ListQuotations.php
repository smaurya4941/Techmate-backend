<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Filament\Resources\Quotations\QuotationResource;
use App\Support\Permissions;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListQuotations extends ListRecords
{
    protected static string $resource = QuotationResource::class;

    protected function getHeaderActions(): array
    {
        return []; // quotations start from a lead
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'approval' => Tab::make('Awaiting approval')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending_approval'))
                ->visible(fn () => auth()->user()->hasPermission(Permissions::QUOTATIONS_APPROVE)),
            'drafts' => Tab::make('Drafts')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'draft')),
            'sent' => Tab::make('Awaiting customer')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'sent')),
            'accepted' => Tab::make('Accepted')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'accepted')),
        ];
    }
}
