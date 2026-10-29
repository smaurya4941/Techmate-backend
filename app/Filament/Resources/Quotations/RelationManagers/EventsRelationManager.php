<?php

namespace App\Filament\Resources\Quotations\RelationManagers;

use App\Filament\Support\Status;
use App\Models\NotificationOutbox;
use App\Models\Quotation;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Approval / sending / response history of this version, with the e-mail delivery state kept separately. */
class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $title = 'History';

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
                    ->state(fn ($record) => $record->to_status && $record->from_status !== $record->to_status
                        ? Status::label($record->from_status).' → '.Status::label($record->to_status) : null)
                    ->placeholder('-'),
                TextColumn::make('note')->wrap()->placeholder('-')->limit(300),
                TextColumn::make('user.name')->label('By')->placeholder('System'),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->heading('History')
            ->description(function () {
                /** @var Quotation $quotation */
                $quotation = $this->getOwnerRecord();
                $mails = NotificationOutbox::query()->where('type', 'quotation.sent')
                    ->where('payload->quotation_id', $quotation->id)->orderBy('id')->get();

                return $mails->isEmpty()
                    ? 'No e-mail queued yet.'
                    : 'E-mail delivery: '.$mails->map(fn ($m) => Status::label($m->status).($m->last_error ? ' ('.$m->last_error.')' : ''))->implode('; ');
            });
    }
}
