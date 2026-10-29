<?php

namespace App\Filament\Resources\FollowUps;

use App\Filament\Resources\FollowUps\Pages\ListFollowUps;
use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Support\FollowUpActions;
use App\Filament\Support\Status;
use App\Models\FollowUp;
use App\Policies\LeadPolicy;
use App\Support\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The follow-up work queue: due today, overdue, upcoming, recently completed. All views are
 * queries over stored tasks and dates. Executives see only tasks assigned to them on leads
 * they can access; managers and admins see the whole team's.
 */
class FollowUpResource extends Resource
{
    protected static ?string $model = FollowUp::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static string|\UnitEnum|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 15;

    protected static ?string $modelLabel = 'follow-up';

    protected static ?string $recordTitleAttribute = 'title';

    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return parent::getEloquentQuery()
            ->whereHas('lead', fn (Builder $lead) => LeadPolicy::scope($lead, $user))
            ->when(! $user->hasPermission(Permissions::LEADS_VIEW_ALL), fn (Builder $q) => $q->where('assigned_to', $user->getKey()));
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->overdue()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['lead:id,public_reference,customer_name,company_name,status', 'assignee:id,name']))
            ->columns([
                TextColumn::make('title')->wrap()->searchable()->description(fn (FollowUp $record) => $record->description)->weight('medium'),
                TextColumn::make('lead.public_reference')->label('Lead')->searchable()
                    ->url(fn (FollowUp $record) => LeadResource::getUrl('view', ['record' => $record->lead_id]))
                    ->description(fn (FollowUp $record) => $record->lead->customer_name),
                TextColumn::make('assignee.name')->label('Assigned to')->placeholder('Unassigned'),
                TextColumn::make('due_at')->label('Due')->dateTime('d M Y H:i')->sortable()
                    ->color(fn (FollowUp $record) => $record->isOverdue() ? 'danger' : null),
                TextColumn::make('display_status')->label('Status')->badge()
                    ->state(fn (FollowUp $record) => $record->isOverdue() ? 'overdue' : $record->status)
                    ->color(fn ($state) => Status::FOLLOW_UP[$state] ?? 'gray')
                    ->formatStateUsing(fn ($state) => Status::label($state)),
            ])
            ->defaultSort('due_at', 'asc')
            ->filters([
                SelectFilter::make('assigned_to')->label('Assigned to')
                    ->relationship('assignee', 'name')
                    ->visible(fn () => auth()->user()->hasPermission(Permissions::LEADS_VIEW_ALL)),
            ])
            ->recordActions(FollowUpActions::all());
    }

    public static function getPages(): array
    {
        return ['index' => ListFollowUps::route('/')];
    }
}
