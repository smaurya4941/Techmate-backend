<?php

namespace App\Filament\Resources\Pages\Tables;

use App\Filament\Resources\Pages\Schemas\PageForm;
use App\Models\Page;
use App\Services\ContentPublisher;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class PagesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable()->wrap(),
                TextColumn::make('slug')->searchable()->toggleable(),
                TextColumn::make('section')->badge()->formatStateUsing(fn ($state) => ucfirst($state))->sortable(),
                TextColumn::make('status')->badge()->sortable()
                    ->color(fn ($state) => $state === 'published' ? 'success' : 'warning')
                    ->formatStateUsing(fn ($state) => ucfirst($state)),
                TextColumn::make('sort_order')->label('Order')->sortable(),
                TextColumn::make('updated_at')->label('Updated')->dateTime('d M Y H:i')->sortable(),
            ])
            ->defaultSort('section')
            ->filters([
                SelectFilter::make('section')->options(array_map(fn ($l) => explode(' (', $l)[0], PageForm::SECTION_LABELS)),
                SelectFilter::make('status')->options(['draft' => 'Draft', 'published' => 'Published']),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('publish')->label('Publish')->icon('heroicon-o-arrow-up-circle')->color('success')
                    ->requiresConfirmation()->authorize('update')
                    ->visible(fn (Page $record) => ! $record->trashed() && $record->status !== 'published')
                    ->action(function (Page $record) {
                        app(ContentPublisher::class)->publish($record, auth()->user());
                        Notification::make()->success()->title('Published: '.$record->title)->send();
                    }),
                Action::make('unpublish')->label('Unpublish')->icon('heroicon-o-arrow-down-circle')->color('warning')
                    ->requiresConfirmation()->authorize('update')
                    ->visible(fn (Page $record) => ! $record->trashed() && $record->status === 'published')
                    ->action(function (Page $record) {
                        app(ContentPublisher::class)->unpublish($record, auth()->user());
                        Notification::make()->success()->title('Unpublished: '.$record->title)->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }
}
