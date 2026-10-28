<?php

namespace App\Filament\Resources\Quotations\Tables;

use App\Models\Quotation;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class QuotationsTable
{
    public const STATUS_COLORS = [
        'draft' => 'gray', 'sent' => 'info', 'accepted' => 'success',
        'rejected' => 'danger', 'expired' => 'warning', 'cancelled' => 'gray',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('lead:id,public_reference,assigned_to'))
            ->columns([
                TextColumn::make('quotation_number')->label('Number')->searchable()->sortable()->weight('medium'),
                TextColumn::make('lead.public_reference')->label('Lead')->searchable(),
                TextColumn::make('customer_name_snapshot')->label('Customer')
                    ->description(fn (Quotation $record) => $record->company_name_snapshot)
                    ->searchable(['customer_name_snapshot', 'company_name_snapshot']),
                TextColumn::make('status')->badge()->sortable()
                    ->color(fn ($state) => self::STATUS_COLORS[$state] ?? 'gray')
                    ->formatStateUsing(fn ($state) => ucfirst($state)),
                TextColumn::make('issue_date')->date('d M Y')->sortable(),
                TextColumn::make('valid_until')->date('d M Y')->placeholder('-')->sortable(),
                TextColumn::make('grand_total')->label('Total')->sortable()
                    ->formatStateUsing(fn ($state, Quotation $record) => $record->currency_code.' '.number_format((float) $state, 2)),
            ])
            ->defaultSort('issue_date', 'desc')
            ->filters([
                SelectFilter::make('status')->options(array_combine(Quotation::STATUSES, array_map('ucfirst', Quotation::STATUSES)))->multiple(),
            ])
            ->recordActions([ViewAction::make()]);
    }
}
