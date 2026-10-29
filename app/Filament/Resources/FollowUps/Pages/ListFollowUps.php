<?php

namespace App\Filament\Resources\FollowUps\Pages;

use App\Filament\Resources\FollowUps\FollowUpResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListFollowUps extends ListRecords
{
    protected static string $resource = FollowUpResource::class;

    public function getTabs(): array
    {
        $count = fn (callable $scope) => fn () => $scope(FollowUpResource::getEloquentQuery())->count();

        return [
            'overdue' => Tab::make('Overdue')->badge($count(fn (Builder $q) => $q->overdue()))->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->overdue()),
            'today' => Tab::make('Due today')->badge($count(fn (Builder $q) => $q->dueToday()))
                ->modifyQueryUsing(fn (Builder $query) => $query->dueToday()),
            'upcoming' => Tab::make('Upcoming')->modifyQueryUsing(fn (Builder $query) => $query->upcoming()),
            'completed' => Tab::make('Completed recently')->modifyQueryUsing(fn (Builder $query) => $query->recentlyCompleted()->orderByDesc('completed_at')),
            'all' => Tab::make('All'),
        ];
    }

    public function getDefaultActiveTab(): string|int|null
    {
        return 'today';
    }
}
