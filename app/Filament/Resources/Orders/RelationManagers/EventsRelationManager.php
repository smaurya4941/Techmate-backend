<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Filament\Support\Status;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Fulfilment and payment timeline. Append-only. */
class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $title = 'Fulfilment timeline';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user:id,name'))
            ->columns([
                TextColumn::make('occurred_at')->label('When')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('event')->badge()->formatStateUsing(fn ($state) => Status::label($state)),
                TextColumn::make('transition')->label('Status')
                    ->state(fn ($r) => $r->to_status && $r->from_status !== $r->to_status ? Status::label($r->from_status).' → '.Status::label($r->to_status) : null)
                    ->placeholder('-'),
                TextColumn::make('note')->wrap()->placeholder('-'),
                TextColumn::make('user.name')->label('By')->placeholder('System'),
            ])
            ->defaultSort('occurred_at', 'desc');
    }
}
