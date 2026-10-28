<?php

namespace App\Filament\Resources\Leads\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Read-only chronological timeline. Entries are created only through
 * LeadWorkflow (header actions) and can never be edited or deleted.
 */
class ActivitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';

    protected static ?string $title = 'Activity timeline';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('description')
            ->modifyQueryUsing(fn ($query) => $query->with('user:id,name'))
            ->columns([
                TextColumn::make('occurred_at')->label('When')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('activity_type')->label('Type')->badge()
                    ->formatStateUsing(fn ($state) => str_replace('_', ' ', ucfirst($state))),
                TextColumn::make('description')->wrap()->placeholder('-')->limit(300),
                TextColumn::make('status_change')->label('Status change')
                    ->state(fn ($record) => $record->new_status ? ucfirst((string) $record->old_status).' → '.ucfirst($record->new_status) : null)
                    ->placeholder('-'),
                TextColumn::make('user.name')->label('By')->placeholder('System'),
            ])
            ->defaultSort('occurred_at', 'asc')
            ->paginated([10, 25, 50]);
    }
}
