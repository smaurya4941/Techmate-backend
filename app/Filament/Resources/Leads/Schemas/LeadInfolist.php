<?php

namespace App\Filament\Resources\Leads\Schemas;

use App\Filament\Resources\Leads\Tables\LeadsTable;
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
                    ->color(fn ($state) => LeadsTable::STATUS_COLORS[$state] ?? 'gray')
                    ->formatStateUsing(fn ($state) => ucfirst($state)),
                TextEntry::make('priority')->badge()->formatStateUsing(fn ($state) => ucfirst($state)),
                TextEntry::make('assignee.name')->label('Assigned to')->placeholder('Unassigned'),
                TextEntry::make('lead_type')->label('Type')->formatStateUsing(fn ($state) => ucfirst($state)),
                TextEntry::make('source')->placeholder('-'),
                TextEntry::make('created_at')->label('Received')->dateTime('d M Y H:i'),
                TextEntry::make('last_contacted_at')->label('Last contacted')->dateTime('d M Y H:i')->placeholder('Not yet'),
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
            ]),
            Section::make('Enquiry')->schema([
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
