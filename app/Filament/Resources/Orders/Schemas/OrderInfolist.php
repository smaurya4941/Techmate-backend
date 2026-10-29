<?php

namespace App\Filament\Resources\Orders\Schemas;

use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Filament\Support\Status;
use App\Models\Order;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $money = fn ($state, Order $record) => Status::money($record->currency_code, $state);

        return $schema->components([
            // Commercial, payment and fulfilment are three separate facts and are shown side by side.
            Section::make('Order')->columns(4)->schema([
                TextEntry::make('order_number')->label('Order')->weight('bold')->copyable(),
                TextEntry::make('status')->label('Fulfilment status')->badge()
                    ->color(fn ($state) => Status::ORDER[$state] ?? 'gray')
                    ->formatStateUsing(fn ($state) => Status::label($state)),
                TextEntry::make('payment_status')->label('Payment status')->badge()
                    ->state(fn (Order $record) => $record->paymentStatus())
                    ->color(fn ($state) => Status::PAYMENT[$state] ?? 'gray')
                    ->formatStateUsing(fn ($state) => Status::label($state)),
                TextEntry::make('ordered_at')->label('Ordered')->dateTime('d M Y H:i'),
                TextEntry::make('lead.public_reference')->label('Lead')
                    ->url(fn (Order $record) => auth()->user()->can('view', $record->lead) ? LeadResource::getUrl('view', ['record' => $record->lead_id]) : null),
                TextEntry::make('quotation.quotation_number')->label('Accepted quotation')
                    ->url(fn (Order $record) => auth()->user()->can('view', $record->quotation) ? QuotationResource::getUrl('view', ['record' => $record->quotation_id]) : null),
                TextEntry::make('creator.name')->label('Created by')->placeholder('-'),
                TextEntry::make('confirmed_at')->label('Confirmed')->dateTime('d M Y H:i')->placeholder('-'),
                TextEntry::make('dispatched_at')->label('Dispatched')->dateTime('d M Y H:i')->placeholder('-'),
                TextEntry::make('delivered_at')->label('Delivered')->dateTime('d M Y H:i')->placeholder('-'),
                TextEntry::make('installation')->label('Installation')
                    ->state(fn (Order $record) => ! $record->installation_required ? 'Not included' : ($record->installation_completed_at ? 'Completed '.$record->installation_completed_at->format('d M Y') : 'Pending')),
                TextEntry::make('completed_at')->label('Completed')->dateTime('d M Y H:i')->placeholder('-'),
                TextEntry::make('cancellation_reason')->label('Cancelled because')->columnSpanFull()
                    ->visible(fn (Order $record) => $record->status === 'cancelled'),
            ]),
            Section::make('Money')->columns(4)->schema([
                TextEntry::make('grand_total')->label('Order value')->weight('bold')->formatStateUsing($money),
                TextEntry::make('received')->label('Received')->state(fn (Order $r) => Status::money($r->currency_code, $r->amountReceived())),
                TextEntry::make('verified')->label('Verified')->state(fn (Order $r) => Status::money($r->currency_code, $r->amountVerified())),
                TextEntry::make('balance')->label('Balance due')->weight('bold')
                    ->state(fn (Order $r) => Status::money($r->currency_code, $r->balanceDue())),
                TextEntry::make('subtotal')->formatStateUsing($money)->color('gray'),
                TextEntry::make('discount_total')->label('Discount')->formatStateUsing($money)->color('gray'),
                TextEntry::make('tax_total')->label('Tax')->formatStateUsing($money)->color('gray'),
                TextEntry::make('freight_amount')->label('Freight')->formatStateUsing($money)->color('gray'),
            ]),
            Section::make('Customer & delivery')->columns(3)->schema([
                TextEntry::make('customer_name_snapshot')->label('Customer'),
                TextEntry::make('company_name_snapshot')->label('Company')->placeholder('-'),
                TextEntry::make('customer_email_snapshot')->label('Email')->placeholder('-'),
                TextEntry::make('customer_phone_snapshot')->label('Phone')->placeholder('-'),
                TextEntry::make('billing_address_snapshot')->label('Billing address')->placeholder('-'),
                TextEntry::make('delivery_address')->label('Delivery address')->placeholder('-'),
                TextEntry::make('payment_terms')->placeholder('-')->extraAttributes(['class' => 'whitespace-pre-line']),
                TextEntry::make('delivery_terms')->placeholder('-')->extraAttributes(['class' => 'whitespace-pre-line']),
                TextEntry::make('notes')->placeholder('-')->extraAttributes(['class' => 'whitespace-pre-line']),
            ]),
        ]);
    }
}
