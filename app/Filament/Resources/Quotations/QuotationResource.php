<?php

namespace App\Filament\Resources\Quotations;

use App\Filament\Resources\Quotations\Pages\CreateQuotation;
use App\Filament\Resources\Quotations\Pages\EditQuotation;
use App\Filament\Resources\Quotations\Pages\ListQuotations;
use App\Filament\Resources\Quotations\Pages\ViewQuotation;
use App\Filament\Resources\Quotations\RelationManagers\EventsRelationManager;
use App\Filament\Resources\Quotations\Schemas\QuotationForm;
use App\Filament\Resources\Quotations\Schemas\QuotationInfolist;
use App\Filament\Resources\Quotations\Tables\QuotationsTable;
use App\Models\Quotation;
use App\Policies\QuotationPolicy;
use App\Support\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Quotations are prepared from a lead (Create needs ?lead=ID), edited only while draft,
 * and moved through approval, sending and the customer's response by QuotationService.
 * Visibility is scoped in getEloquentQuery() exactly like leads.
 */
class QuotationResource extends Resource
{
    protected static ?string $model = Quotation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|\UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'quotation_number';

    public static function getEloquentQuery(): Builder
    {
        return QuotationPolicy::scope(parent::getEloquentQuery(), auth()->user());
    }

    /** Quotations are only ever created from a lead, so there is no standalone "New" button. */
    public static function canCreate(): bool
    {
        return auth()->user()?->hasPermission(Permissions::QUOTATIONS_MANAGE) ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        if (! auth()->user()->hasPermission(Permissions::QUOTATIONS_APPROVE)) {
            return null;
        }

        $count = static::getEloquentQuery()->where('status', 'pending_approval')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function form(Schema $schema): Schema
    {
        return QuotationForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return QuotationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return QuotationsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [EventsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListQuotations::route('/'),
            'create' => CreateQuotation::route('/create'),
            'view' => ViewQuotation::route('/{record}'),
            'edit' => EditQuotation::route('/{record}/edit'),
        ];
    }
}
