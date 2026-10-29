<?php

namespace App\Filament\Resources\Leads\Tables;

use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Support\Guarded;
use App\Filament\Support\Status;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadWorkflow;
use App\Support\Permissions;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class LeadsTable
{
    public const STATUS_COLORS = Status::LEAD;

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['assignee:id,name', 'products:id,lead_id,product_name_snapshot,quantity'])
                ->withMax('activities as last_activity_at', 'occurred_at')
                ->withMin(['followUps as next_follow_up_at' => fn ($q) => $q->active()], 'due_at'))
            ->columns([
                TextColumn::make('public_reference')->label('Reference')->searchable()->sortable()->copyable()->weight('medium'),
                TextColumn::make('customer_name')->label('Customer')
                    ->description(fn (Lead $record) => $record->company_name)
                    ->searchable(['customer_name', 'company_name', 'email', 'phone'])->sortable(),
                TextColumn::make('products_summary')->label('Products')
                    ->state(fn (Lead $record) => $record->products->isEmpty()
                        ? ($record->subject ?: '-')
                        : $record->products->take(2)->map(fn ($p) => "{$p->quantity} x {$p->product_name_snapshot}")->implode(', ').($record->products->count() > 2 ? ' +'.($record->products->count() - 2).' more' : ''))
                    ->limit(48)->tooltip(fn (Lead $record) => $record->products->map(fn ($p) => "{$p->quantity} x {$p->product_name_snapshot}")->implode("\n") ?: null)
                    ->toggleable(),
                TextColumn::make('status')->badge()->sortable()
                    ->color(fn ($state) => Status::LEAD[$state] ?? 'gray')
                    ->formatStateUsing(fn ($state) => Status::label($state)),
                TextColumn::make('priority')->badge()->sortable()
                    ->color(fn ($state) => Status::PRIORITY[$state] ?? 'gray')
                    ->formatStateUsing(fn ($state) => Status::label($state)),
                TextColumn::make('assignee.name')->label('Assigned to')->placeholder('Unassigned'),
                TextColumn::make('source')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_activity_at')->label('Last activity')->since()->placeholder('-')->sortable(),
                TextColumn::make('next_follow_up_at')->label('Next follow-up')->dateTime('d M Y H:i')
                    ->placeholder('-')->sortable()
                    ->color(fn ($state) => $state && now()->gt($state) ? 'danger' : null)
                    ->icon(fn ($state) => $state && now()->gt($state) ? 'heroicon-o-exclamation-triangle' : null),
                TextColumn::make('created_at')->label('Received')->dateTime('d M Y H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(Status::options(Lead::STATUSES))->multiple(),
                SelectFilter::make('priority')->options(Status::options(Lead::PRIORITIES)),
                SelectFilter::make('lead_type')->label('Type')->options(Status::options(Lead::TYPES)),
                SelectFilter::make('assigned_to')->label('Assigned to')
                    ->options(fn () => User::assignable()->orderBy('name')->pluck('name', 'id'))
                    ->visible(fn () => auth()->user()->hasPermission(Permissions::LEADS_VIEW_ALL)),
                SelectFilter::make('source')
                    ->options(fn () => Lead::query()->whereNotNull('source')->distinct()->orderBy('source')->pluck('source', 'source')),
                SelectFilter::make('follow_up')->label('Follow-up')
                    ->options(['overdue' => 'Overdue', 'upcoming' => 'Scheduled', 'none' => 'None scheduled'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'overdue' => $query->whereHas('followUps', fn ($q) => $q->overdue()),
                        'upcoming' => $query->whereHas('followUps', fn ($q) => $q->active()->where('due_at', '>=', now())),
                        'none' => $query->whereDoesntHave('followUps', fn ($q) => $q->active()),
                        default => $query,
                    }),
                Filter::make('created')->label('Received')
                    ->schema([DatePicker::make('from'), DatePicker::make('until')])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))),
            ])
            ->recordActions([ViewAction::make()])
            ->recordUrl(fn (Lead $record) => LeadResource::getUrl('view', ['record' => $record]))
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('assign')
                        ->label('Assign selected')
                        ->icon('heroicon-o-user-plus')
                        ->visible(fn () => auth()->user()->hasPermission(Permissions::LEADS_ASSIGN))
                        ->authorizeIndividualRecords('assign')
                        ->schema([
                            Select::make('assigned_to')->label('Assign to')
                                ->options(fn () => User::assignable()->orderBy('name')->pluck('name', 'id'))->required()->searchable(),
                            Textarea::make('note')->rows(2)->maxLength(500),
                        ])
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records, array $data) {
                            Guarded::run(function () use ($records, $data) {
                                $workflow = app(LeadWorkflow::class);
                                $records->each(fn (Lead $lead) => $workflow->assign($lead, (int) $data['assigned_to'], auth()->user(), $data['note'] ?? null));
                            }, $records->count().' lead(s) assigned');
                        }),
                ]),
            ]);
    }
}
