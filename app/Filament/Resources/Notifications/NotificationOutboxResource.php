<?php

namespace App\Filament\Resources\Notifications;

use App\Filament\Resources\Notifications\Pages\ListNotificationOutbox;
use App\Filament\Support\Guarded;
use App\Filament\Support\Status;
use App\Models\NotificationOutbox;
use App\Services\Outbox;
use App\Support\AuditLog;
use App\Support\Permissions;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

/**
 * Where an administrator investigates and retries e-mail delivery. Rows describe what the
 * system owes; "sent" means the mail transport accepted it, not that it reached an inbox.
 * Retrying resets the same row (same dedupe key), so it can never create a second message.
 */
class NotificationOutboxResource extends Resource
{
    protected static ?string $model = NotificationOutbox::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|\UnitEnum|null $navigationGroup = 'Administration';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'notifications';

    protected static ?string $modelLabel = 'notification';

    protected static ?string $navigationLabel = 'Notifications';

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

    public static function getNavigationBadge(): ?string
    {
        $failed = NotificationOutbox::query()->where('status', 'failed')->count();

        return $failed > 0 ? (string) $failed : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('lead:id,public_reference'))
            ->columns([
                TextColumn::make('created_at')->label('Queued')->dateTime('d M Y H:i')->sortable(),
                TextColumn::make('type')->formatStateUsing(fn ($state) => NotificationOutbox::TYPES[$state] ?? $state)->searchable(),
                TextColumn::make('recipient_email')->label('To')->searchable(),
                TextColumn::make('lead.public_reference')->label('Lead')->searchable()->placeholder('-'),
                TextColumn::make('status')->badge()->sortable()
                    ->color(fn ($state) => ['pending' => 'warning', 'sending' => 'info', 'sent' => 'success', 'failed' => 'danger'][$state] ?? 'gray')
                    ->formatStateUsing(fn ($state) => Status::label($state)),
                TextColumn::make('attempts')->numeric(),
                TextColumn::make('last_error')->label('Last error')->wrap()->limit(120)->placeholder('-'),
                TextColumn::make('sent_at')->label('Sent')->dateTime('d M Y H:i')->placeholder('-'),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')->options(Status::options(['pending', 'sending', 'sent', 'failed']))->default('failed'),
                SelectFilter::make('type')->options(NotificationOutbox::TYPES),
            ])
            ->recordActions([
                Action::make('retry')->label('Retry')->icon('heroicon-o-arrow-path')->color('warning')
                    ->visible(fn (NotificationOutbox $record) => $record->status === 'failed')
                    ->requiresConfirmation()
                    ->action(function (NotificationOutbox $record) {
                        Outbox::retry($record);
                        AuditLog::record('notification.retried', ['outbox_id' => $record->id], $record);
                        Guarded::run(fn () => true, 'Retry queued');
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('retrySelected')->label('Retry failed')->icon('heroicon-o-arrow-path')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records) {
                            $records->where('status', 'failed')->each(fn (NotificationOutbox $row) => Outbox::retry($row));
                            Guarded::run(fn () => true, 'Retry queued');
                        }),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListNotificationOutbox::route('/')];
    }
}
