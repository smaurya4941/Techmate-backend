<?php

namespace App\Filament\Resources\Quotations\Tables;

use App\Filament\Support\Status;
use App\Models\Quotation;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class QuotationsTable
{
    public const STATUS_COLORS = Status::QUOTATION;

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('lead:id,public_reference,assigned_to'))
            ->columns([
                TextColumn::make('quotation_number')->label('Number')->searchable()->sortable()->weight('medium'),
                TextColumn::make('version')->label('Ver.')->prefix('v')->sortable(),
                TextColumn::make('lead.public_reference')->label('Lead')->searchable(),
                TextColumn::make('customer_name_snapshot')->label('Customer')
                    ->description(fn (Quotation $record) => $record->company_name_snapshot)
                    ->searchable(['customer_name_snapshot', 'company_name_snapshot']),
                TextColumn::make('status')->badge()->sortable()
                    ->color(fn ($state) => Status::QUOTATION[$state] ?? 'gray')
                    ->formatStateUsing(fn ($state) => Status::label($state)),
                IconColumn::make('is_current')->label('Current')->boolean()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('issue_date')->date('d M Y')->sortable(),
                TextColumn::make('valid_until')->date('d M Y')->placeholder('-')->sortable(),
                TextColumn::make('grand_total')->label('Total')->sortable()
                    ->formatStateUsing(fn ($state, Quotation $record) => Status::money($record->currency_code, $state)),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')->options(Status::options(Quotation::STATUSES))->multiple(),
                Filter::make('current_only')->label('Current versions only')->default()
                    ->query(fn ($query) => $query->where('is_current', true)),
            ])
            ->recordActions([ViewAction::make()]);
    }
}
