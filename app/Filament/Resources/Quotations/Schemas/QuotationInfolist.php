<?php

namespace App\Filament\Resources\Quotations\Schemas;

use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Support\Status;
use App\Models\Quotation;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class QuotationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        $money = fn ($state, Quotation $record) => Status::money($record->currency_code, $state);

        return $schema->components([
            Section::make('Quotation')->columns(4)->schema([
                TextEntry::make('quotation_number')->label('Number')->weight('bold')->copyable(),
                TextEntry::make('status')->badge()
                    ->color(fn ($state) => Status::QUOTATION[$state] ?? 'gray')
                    ->formatStateUsing(fn ($state) => Status::label($state)),
                TextEntry::make('version')->prefix('v')
                    ->state(fn (Quotation $record) => $record->version.($record->is_current ? ' (current)' : ' (older version)')),
                TextEntry::make('lead.public_reference')->label('Lead')
                    ->url(fn (Quotation $record) => LeadResource::getUrl('view', ['record' => $record->lead_id])),
                TextEntry::make('currency_code')->label('Currency'),
                TextEntry::make('issue_date')->date('d M Y'),
                TextEntry::make('valid_until')->date('d M Y')->placeholder('-'),
                TextEntry::make('creator.name')->label('Prepared by')->placeholder('-'),
                TextEntry::make('submitted_at')->label('Submitted')->dateTime('d M Y H:i')->placeholder('-'),
                TextEntry::make('approver.name')->label('Approved by')->placeholder('-'),
                TextEntry::make('approved_at')->label('Approved')->dateTime('d M Y H:i')->placeholder('-'),
                TextEntry::make('sent_at')->label('Sent')->dateTime('d M Y H:i')->placeholder('-'),
                TextEntry::make('approval_note')->label('Approval note')->placeholder('-')->columnSpanFull()
                    ->visible(fn (Quotation $record) => (bool) $record->approval_note),
            ]),
            Section::make('Customer response')->columns(4)
                ->visible(fn (Quotation $record) => in_array($record->status, ['accepted', 'rejected'], true))
                ->schema([
                    TextEntry::make('accepted_at')->label('Accepted')->dateTime('d M Y H:i')->visible(fn (Quotation $r) => $r->status === 'accepted'),
                    TextEntry::make('acceptance_method')->label('Method')->visible(fn (Quotation $r) => $r->status === 'accepted')
                        ->formatStateUsing(fn ($state) => Quotation::ACCEPTANCE_METHODS[$state] ?? $state),
                    TextEntry::make('acceptance_reference')->label('Reference')->placeholder('-')->visible(fn (Quotation $r) => $r->status === 'accepted'),
                    TextEntry::make('acceptance_note')->label('Evidence')->placeholder('-')->visible(fn (Quotation $r) => $r->status === 'accepted')->columnSpanFull(),
                    TextEntry::make('rejected_at')->label('Rejected')->dateTime('d M Y H:i')->visible(fn (Quotation $r) => $r->status === 'rejected'),
                    TextEntry::make('rejection_reason')->label('Reason')->visible(fn (Quotation $r) => $r->status === 'rejected')->columnSpan(3),
                ]),
            Section::make('Customer (snapshot at preparation)')->columns(3)->schema([
                TextEntry::make('customer_name_snapshot')->label('Name'),
                TextEntry::make('company_name_snapshot')->label('Company')->placeholder('-'),
                TextEntry::make('customer_email_snapshot')->label('Email')->placeholder('-'),
                TextEntry::make('customer_phone_snapshot')->label('Phone')->placeholder('-'),
                TextEntry::make('billing_address_snapshot')->label('Billing address')->placeholder('-')->columnSpan(2),
            ]),
            Section::make('Line items')->schema([
                RepeatableEntry::make('items')->hiddenLabel()->columns(7)->schema([
                    TextEntry::make('product_name_snapshot')->label('Item')->columnSpan(2),
                    TextEntry::make('product_code_snapshot')->label('Code')->placeholder('-'),
                    TextEntry::make('quantity')->label('Qty'),
                    TextEntry::make('unit_price')->label('Unit price')->formatStateUsing(fn ($state) => number_format((float) $state, 2)),
                    TextEntry::make('discount_amount')->label('Discount')->formatStateUsing(fn ($state) => number_format((float) $state, 2)),
                    TextEntry::make('line_total')->label('Line total (incl. tax)')->formatStateUsing(fn ($state) => number_format((float) $state, 2)),
                    TextEntry::make('description_snapshot')->label('Description')->placeholder('-')->columnSpanFull(),
                ])->placeholder('No line items.'),
            ]),
            Section::make('Totals')->columns(5)->schema([
                TextEntry::make('subtotal')->formatStateUsing($money),
                TextEntry::make('discount_total')->label('Discount')->formatStateUsing($money),
                TextEntry::make('tax_total')->label('Tax')->formatStateUsing($money),
                TextEntry::make('freight_amount')->label('Freight')->formatStateUsing($money),
                TextEntry::make('grand_total')->label('Grand total')->weight('bold')->formatStateUsing($money),
            ]),
            Section::make('Terms & notes')->collapsible()->columns(2)->schema([
                TextEntry::make('payment_terms')->placeholder('-')->extraAttributes(['class' => 'whitespace-pre-line']),
                TextEntry::make('delivery_terms')->placeholder('-')->extraAttributes(['class' => 'whitespace-pre-line']),
                TextEntry::make('warranty_terms')->placeholder('-')->extraAttributes(['class' => 'whitespace-pre-line']),
                TextEntry::make('terms_and_conditions')->label('Other terms')->placeholder('-')->extraAttributes(['class' => 'whitespace-pre-line']),
                TextEntry::make('notes')->placeholder('-')->extraAttributes(['class' => 'whitespace-pre-line'])->columnSpanFull(),
            ]),
        ]);
    }
}
