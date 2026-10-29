<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Filament\Support\Status;
use App\Models\Order;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['lead:id,public_reference,assigned_to', 'payments']))
            ->columns([
                TextColumn::make('order_number')->label('Order')->searchable()->sortable()->weight('medium')->copyable(),
                TextColumn::make('lead.public_reference')->label('Lead')->searchable(),
                TextColumn::make('customer_name_snapshot')->label('Customer')
                    ->description(fn (Order $record) => $record->company_name_snapshot)
                    ->searchable(['customer_name_snapshot', 'company_name_snapshot']),
                TextColumn::make('status')->label('Fulfilment')->badge()->sortable()
                    ->color(fn ($state) => Status::ORDER[$state] ?? 'gray')
                    ->formatStateUsing(fn ($state) => Status::label($state)),
                TextColumn::make('payment_status')->label('Payment')->badge()
                    ->state(fn (Order $record) => $record->paymentStatus())
                    ->color(fn ($state) => Status::PAYMENT[$state] ?? 'gray')
                    ->formatStateUsing(fn ($state) => Status::label($state)),
                TextColumn::make('grand_total')->label('Order value')->sortable()
                    ->formatStateUsing(fn ($state, Order $record) => Status::money($record->currency_code, $state)),
                TextColumn::make('balance')->label('Balance due')
                    ->state(fn (Order $record) => $record->status === 'cancelled' ? null : Status::money($record->currency_code, $record->balanceDue()))
                    ->placeholder('-'),
                TextColumn::make('ordered_at')->label('Ordered')->dateTime('d M Y')->sortable(),
            ])
            ->defaultSort('ordered_at', 'desc')
            ->filters([
                SelectFilter::make('status')->label('Fulfilment')->options(Status::options(Order::STATUSES))->multiple(),
            ])
            ->recordActions([ViewAction::make()]);
    }
}
