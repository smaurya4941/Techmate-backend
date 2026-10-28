<?php

namespace App\Filament\Resources\Quotations\Schemas;

use App\Filament\Resources\Quotations\Tables\QuotationsTable;
use App\Models\Quotation;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class QuotationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $money = fn ($state, Quotation $record) => $record->currency_code.' '.number_format((float) $state, 2);

        return $schema->components([
            Section::make('Quotation')->columns(4)->schema([
                TextEntry::make('quotation_number')->label('Number')->weight('bold')->copyable(),
                TextEntry::make('status')->badge()
                    ->color(fn ($state) => QuotationsTable::STATUS_COLORS[$state] ?? 'gray')
                    ->formatStateUsing(fn ($state) => ucfirst($state)),
                TextEntry::make('lead.public_reference')->label('Lead'),
                TextEntry::make('currency_code')->label('Currency'),
                TextEntry::make('issue_date')->date('d M Y'),
                TextEntry::make('valid_until')->date('d M Y')->placeholder('-'),
                TextEntry::make('sent_at')->dateTime('d M Y H:i')->placeholder('-'),
                TextEntry::make('accepted_at')->dateTime('d M Y H:i')->placeholder('-'),
            ]),
            Section::make('Customer (snapshot at issue)')->columns(3)->schema([
                TextEntry::make('customer_name_snapshot')->label('Name'),
                TextEntry::make('company_name_snapshot')->label('Company')->placeholder('-'),
                TextEntry::make('customer_email_snapshot')->label('Email')->placeholder('-'),
                TextEntry::make('customer_phone_snapshot')->label('Phone')->placeholder('-'),
                TextEntry::make('billing_address_snapshot')->label('Billing address')->placeholder('-')->columnSpan(2),
            ]),
            Section::make('Line items (snapshot at issue)')->schema([
                RepeatableEntry::make('items')->hiddenLabel()->columns(6)->schema([
                    TextEntry::make('product_name_snapshot')->label('Product')->columnSpan(2),
                    TextEntry::make('product_code_snapshot')->label('Code')->placeholder('-'),
                    TextEntry::make('quantity')->label('Qty'),
                    TextEntry::make('unit_price')->label('Unit price')
                        ->formatStateUsing(fn ($state, $record) => number_format((float) $state, 2)),
                    TextEntry::make('line_total')->label('Line total')
                        ->formatStateUsing(fn ($state, $record) => number_format((float) $state, 2)),
                ])->placeholder('No line items.'),
            ]),
            Section::make('Totals')->columns(4)->schema([
                TextEntry::make('subtotal')->formatStateUsing($money),
                TextEntry::make('discount_total')->label('Discount')->formatStateUsing($money),
                TextEntry::make('tax_total')->label('Tax')->formatStateUsing($money),
                TextEntry::make('grand_total')->label('Grand total')->weight('bold')->formatStateUsing($money),
            ]),
            Section::make('Terms & notes')->collapsible()->collapsed()->schema([
                TextEntry::make('terms_and_conditions')->label('Terms')->placeholder('-')->extraAttributes(['class' => 'whitespace-pre-line']),
                TextEntry::make('notes')->placeholder('-')->extraAttributes(['class' => 'whitespace-pre-line']),
            ]),
        ]);
    }
}
