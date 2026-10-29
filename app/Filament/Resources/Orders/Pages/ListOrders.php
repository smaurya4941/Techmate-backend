<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    public function getTabs(): array
    {
        return [
            'all' => Tab::make('All'),
            'confirmation' => Tab::make('Awaiting confirmation')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'pending_confirmation')),
            'in_progress' => Tab::make('In fulfilment')->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', ['confirmed', 'processing', 'ready_for_dispatch', 'dispatched'])),
            'delivered' => Tab::make('Delivered')->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', ['delivered', 'completed'])),
            'cancelled' => Tab::make('Cancelled')->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'cancelled')),
        ];
    }
}
