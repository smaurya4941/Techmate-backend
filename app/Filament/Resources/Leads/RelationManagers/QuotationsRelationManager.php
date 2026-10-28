<?php

namespace App\Filament\Resources\Leads\RelationManagers;

use App\Filament\Resources\Quotations\QuotationResource;
use App\Models\Quotation;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class QuotationsRelationManager extends RelationManager
{
    protected static string $relationship = 'quotations';

    protected static ?string $title = 'Quotations';

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
                TextColumn::make('status')->badge()->formatStateUsing(fn ($state) => ucfirst($state)),
                TextColumn::make('issue_date')->date('d M Y'),
                TextColumn::make('valid_until')->date('d M Y')->placeholder('-'),
                TextColumn::make('grand_total')->label('Total')
                    ->formatStateUsing(fn ($state, Quotation $record) => $record->currency_code.' '.number_format((float) $state, 2)),
            ])
            ->recordUrl(fn (Quotation $record) => QuotationResource::getUrl('view', ['record' => $record]))
            ->defaultSort('issue_date', 'desc');
    }
}
