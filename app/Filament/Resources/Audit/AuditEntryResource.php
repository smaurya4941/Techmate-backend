<?php

namespace App\Filament\Resources\Audit;

use App\Filament\Resources\Audit\Pages\ListAuditEntries;
use App\Models\AuditEntry;
use App\Support\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Read-only business audit trail, for administrators only. Entries cannot be edited or deleted. */
class AuditEntryResource extends Resource
{
    protected static ?string $model = AuditEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'audit-trail';

    protected static ?string $modelLabel = 'audit entry';

    protected static ?string $pluralModelLabel = 'audit trail';

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasPermission(Permissions::SYSTEM_MONITOR) ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('actor:id,name'))
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime('d M Y H:i:s')->sortable(),
                TextColumn::make('event')->badge()->searchable(),
                TextColumn::make('actor.name')->label('Actor')->placeholder('System'),
                TextColumn::make('subject')->state(fn (AuditEntry $r) => $r->subject_type ? "{$r->subject_type} #{$r->subject_id}" : '-'),
                TextColumn::make('context')->label('Details')->wrap()->limit(160)
                    ->state(fn (AuditEntry $r) => collect($r->context ?? [])->map(fn ($v, $k) => $k.': '.(is_scalar($v) || $v === null ? var_export($v, true) : json_encode($v)))->implode(', ')),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('event')->options(fn () => AuditEntry::query()->distinct()->orderBy('event')->pluck('event', 'event')),
                SelectFilter::make('subject_type')->label('Record type')
                    ->options(fn () => AuditEntry::query()->whereNotNull('subject_type')->distinct()->orderBy('subject_type')->pluck('subject_type', 'subject_type')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAuditEntries::route('/')];
    }
}
