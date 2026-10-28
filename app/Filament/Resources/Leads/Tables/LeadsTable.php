<?php

namespace App\Filament\Resources\Leads\Tables;

use App\Filament\Resources\Leads\LeadResource;
use App\Filament\Support\Guarded;
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
    public const STATUS_COLORS = [
        'new' => 'info', 'contacted' => 'gray', 'qualified' => 'primary', 'proposal' => 'warning',
        'won' => 'success', 'lost' => 'danger', 'spam' => 'gray',
    ];

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with('assignee:id,name')
                ->withMin(['followUps as next_follow_up_at' => fn ($q) => $q->where('status', 'pending')], 'due_at'))
            ->columns([
                TextColumn::make('public_reference')->label('Reference')->searchable()->sortable()->copyable()->weight('medium'),
                TextColumn::make('customer_name')->label('Customer')
                    ->description(fn (Lead $record) => $record->company_name)
                    ->searchable(['customer_name', 'company_name', 'email', 'phone'])->sortable(),
                TextColumn::make('lead_type')->label('Type')->badge()->formatStateUsing(fn ($state) => ucfirst($state)),
                TextColumn::make('status')->badge()->sortable()
                    ->color(fn ($state) => self::STATUS_COLORS[$state] ?? 'gray')
                    ->formatStateUsing(fn ($state) => ucfirst($state)),
                TextColumn::make('assignee.name')->label('Assigned to')->placeholder('Unassigned'),
                TextColumn::make('source')->placeholder('-')->toggleable(),
                TextColumn::make('next_follow_up_at')->label('Next follow-up')->dateTime('d M Y H:i')
                    ->placeholder('-')->sortable()
                    ->color(fn ($state) => $state && now()->gt($state) ? 'danger' : null)
                    ->icon(fn ($state) => $state && now()->gt($state) ? 'heroicon-o-exclamation-triangle' : null),
                TextColumn::make('created_at')->label('Received')->dateTime('d M Y H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options(array_combine(Lead::STATUSES, array_map('ucfirst', Lead::STATUSES)))->multiple(),
                SelectFilter::make('lead_type')->label('Type')->options(array_combine(Lead::TYPES, array_map('ucfirst', Lead::TYPES))),
                SelectFilter::make('assigned_to')->label('Assigned to')
                    ->options(fn () => User::assignable()->orderBy('name')->pluck('name', 'id'))
                    ->visible(fn () => auth()->user()->hasPermission(Permissions::LEADS_VIEW_ALL)),
                SelectFilter::make('source')
                    ->options(fn () => Lead::query()->whereNotNull('source')->distinct()->orderBy('source')->pluck('source', 'source')),
                SelectFilter::make('follow_up')->label('Follow-up')
                    ->options(['overdue' => 'Overdue', 'upcoming' => 'Scheduled', 'none' => 'None scheduled'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'overdue' => $query->whereHas('followUps', fn ($q) => $q->overdue()),
                        'upcoming' => $query->whereHas('followUps', fn ($q) => $q->where('status', 'pending')->where('due_at', '>=', now())),
                        'none' => $query->whereDoesntHave('followUps', fn ($q) => $q->where('status', 'pending')),
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
