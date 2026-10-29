<?php

namespace App\Filament\Resources\Leads\Pages;

use App\Filament\Resources\Leads\LeadResource;
use App\Models\Lead;
use App\Support\Permissions;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListLeads extends ListRecords
{
    protected static string $resource = LeadResource::class;

    /** Work queues derived from stored statuses and dates, never from a separate list. */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'unassigned' => Tab::make('Unassigned')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('assigned_to')->whereIn('status', Lead::OPEN_STATUSES))
                ->visible(fn () => auth()->user()->hasPermission(Permissions::LEADS_VIEW_ALL)),
            'new' => Tab::make('New')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'new')),
            'open' => Tab::make('Open pipeline')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', ['assigned', 'contacted', 'qualified', 'quotation_in_progress', 'quotation_sent', 'negotiation'])),
            'won' => Tab::make('Won')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'won')),
            'lost' => Tab::make('Lost')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'lost')),
        ];
    }
}
