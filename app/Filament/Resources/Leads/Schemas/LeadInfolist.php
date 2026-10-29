<?php

namespace App\Filament\Resources\Leads\Schemas;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\Status;
use App\Models\Lead;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LeadInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Lead')->columns(4)->schema([
                TextEntry::make('public_reference')->label('Reference')->copyable()->weight('bold'),
                TextEntry::make('status')->badge()
                    ->color(fn ($state) => Status::LEAD[$state] ?? 'gray')
                    ->formatStateUsing(fn ($state) => Status::label($state)),
                TextEntry::make('priority')->badge()
                    ->color(fn ($state) => Status::PRIORITY[$state] ?? 'gray')
                    ->formatStateUsing(fn ($state) => Status::label($state)),
                TextEntry::make('assignee.name')->label('Owner')->placeholder('Unassigned'),
                TextEntry::make('lead_type')->label('Type')->formatStateUsing(fn ($state) => Status::label($state)),
                TextEntry::make('source')->placeholder('-')
                    ->state(fn (Lead $record) => collect([$record->source, $record->source_page, $record->campaign ? "campaign: {$record->campaign}" : null])->filter()->implode(' · ') ?: null),
                TextEntry::make('submitted_at')->label('Customer submitted')->dateTime('d M Y H:i')->placeholder('-'),
                TextEntry::make('created_at')->label('Lead created')->dateTime('d M Y H:i'),
                TextEntry::make('first_response_at')->label('First response')->dateTime('d M Y H:i')->placeholder('Not yet'),
                TextEntry::make('last_contacted_at')->label('Last contacted')->dateTime('d M Y H:i')->placeholder('Not yet'),
            ]),
            Section::make('Outcome')->columns(4)
                ->visible(fn (Lead $record) => in_array($record->status, ['won', 'lost'], true))
                ->schema([
                    TextEntry::make('closed_at')->label('Closed')->dateTime('d M Y H:i'),
                    TextEntry::make('closer.name')->label('Closed by')->placeholder('-'),
                    TextEntry::make('lost_reason')->label('Lost reason')
                        ->formatStateUsing(fn ($state) => Lead::LOST_REASONS[$state] ?? $state)
                        ->visible(fn (Lead $record) => $record->status === 'lost')->placeholder('-'),
                    TextEntry::make('order.order_number')->label('Order')
                        ->url(fn (Lead $record) => $record->order ? OrderResource::getUrl('view', ['record' => $record->order]) : null)
                        ->visible(fn (Lead $record) => $record->status === 'won')->placeholder('-'),
                    TextEntry::make('outcome_notes')->label('Notes')->placeholder('-')->columnSpanFull()
                        ->extraAttributes(['class' => 'whitespace-pre-line']),
                ]),
            Section::make('Customer')->columns(3)->schema([
                TextEntry::make('customer_name')->label('Name'),
                TextEntry::make('company_name')->label('Company')->placeholder('-'),
                TextEntry::make('email')->placeholder('-')->copyable()->url(fn ($state) => $state ? 'mailto:'.$state : null),
                TextEntry::make('phone')->placeholder('-')->copyable()->url(fn ($state) => $state ? 'tel:'.preg_replace('/[^0-9+]/', '', $state) : null),
                TextEntry::make('alternate_phone')->label('Alternate phone')->placeholder('-'),
                TextEntry::make('location')->label('Location')
                    ->state(fn ($record) => collect([$record->city, $record->state, $record->postal_code, $record->country_code])->filter()->implode(', ') ?: null)
                    ->placeholder('-'),
                TextEntry::make('customer_leads')->label('Customer history')
                    ->state(function (Lead $record) {
                        $others = $record->customer_id ? Lead::query()->where('customer_id', $record->customer_id)->whereKeyNot($record->id)->count() : 0;

                        return $others > 0 ? "{$others} other enquiry(ies) from this customer" : 'First enquiry';
                    })->columnSpanFull(),
            ]),
            // The customer's original words, kept apart from internal notes (those live in the timeline).
            Section::make('Original enquiry')->description('As submitted by the customer. Internal notes are in the activity timeline.')->schema([
                TextEntry::make('subject')->placeholder('-'),
                // Rendered as escaped plain text; customer input is never treated as HTML.
                TextEntry::make('message')->placeholder('No message')->prose(false)->extraAttributes(['class' => 'whitespace-pre-line']),
            ]),
            Section::make('Requested products')->schema([
                RepeatableEntry::make('products')->hiddenLabel()->columns(4)->schema([
                    TextEntry::make('product_name_snapshot')->label('Product'),
                    TextEntry::make('product_code_snapshot')->label('Code')->placeholder('-'),
                    TextEntry::make('quantity')->label('Quantity'),
                    TextEntry::make('customer_notes')->label('Customer notes')->placeholder('-'),
                ])->placeholder('No products were selected.'),
            ])->collapsible(),
        ]);
    }
}
