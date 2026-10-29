<?php

namespace App\Filament\Resources\Leads\RelationManagers;

use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Support\Status;
use App\Models\Quotation;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Every version of every quotation for the lead; open one to approve, send, revise or record the response. */
class QuotationsRelationManager extends RelationManager
{
    protected static string $relationship = 'quotations';

    protected static ?string $title = 'Quotations & revisions';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('quotation_number')
            ->columns([
                TextColumn::make('quotation_number')->label('Number')->weight('medium'),
                TextColumn::make('version')->label('Ver.')->prefix('v'),
                IconColumn::make('is_current')->label('Current')->boolean(),
                TextColumn::make('status')->badge()
                    ->color(fn ($state) => Status::QUOTATION[$state] ?? 'gray')
                    ->formatStateUsing(fn ($state) => Status::label($state)),
                TextColumn::make('issue_date')->date('d M Y'),
                TextColumn::make('valid_until')->date('d M Y')->placeholder('-'),
                TextColumn::make('grand_total')->label('Total')
                    ->formatStateUsing(fn ($state, Quotation $record) => Status::money($record->currency_code, $state)),
            ])
            ->recordUrl(fn (Quotation $record) => QuotationResource::getUrl('view', ['record' => $record]))
            ->defaultSort('id', 'desc');
    }
}
