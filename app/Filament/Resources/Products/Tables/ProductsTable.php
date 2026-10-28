<?php

namespace App\Filament\Resources\Products\Tables;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\ContentPublisher;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['category', 'brand']))
            ->columns([
                ImageColumn::make('primary_image_path')->label('')->disk('public')->square()->size(44),
                TextColumn::make('name')->label('Product')->searchable()->sortable()->wrap()->limit(60),
                TextColumn::make('sku')->label('SKU')->searchable()->sortable()->copyable(),
                TextColumn::make('category.name')->label('Category'),
                TextColumn::make('brand.name')->label('Brand')->placeholder('-'),
                TextColumn::make('status')->badge()->sortable()
                    ->color(fn (string $state) => match ($state) {
                        Product::STATUS_PUBLISHED => 'success',
                        Product::STATUS_ARCHIVED => 'gray',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (string $state) => ucfirst($state)),
                IconColumn::make('is_featured')->label('Featured')->boolean()->sortable(),
                TextColumn::make('updated_at')->label('Updated')->dateTime('d M Y H:i')->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->filters([
                SelectFilter::make('status')->options([
                    Product::STATUS_DRAFT => 'Draft',
                    Product::STATUS_PUBLISHED => 'Published',
                    Product::STATUS_ARCHIVED => 'Archived',
                ]),
                SelectFilter::make('category_id')->label('Category')
                    ->options(fn () => Category::withoutTrashed()->orderBy('name')->pluck('name', 'id'))->searchable(),
                SelectFilter::make('brand_id')->label('Brand')
                    ->options(fn () => Brand::withoutTrashed()->orderBy('name')->pluck('name', 'id'))->searchable(),
                TernaryFilter::make('is_featured')->label('Featured'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                ActionGroup::make([
                    self::statusAction('publish', 'Publish', 'heroicon-o-arrow-up-circle', 'success', [Product::STATUS_DRAFT, Product::STATUS_ARCHIVED]),
                    self::statusAction('unpublish', 'Unpublish', 'heroicon-o-arrow-down-circle', 'warning', [Product::STATUS_PUBLISHED]),
                    self::statusAction('archive', 'Archive', 'heroicon-o-archive-box', 'gray', [Product::STATUS_DRAFT, Product::STATUS_PUBLISHED]),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    self::bulkStatus('publishBulk', 'Publish selected', 'publish'),
                    self::bulkStatus('unpublishBulk', 'Unpublish selected', 'unpublish'),
                    self::bulkStatus('archiveBulk', 'Archive selected', 'archive'),
                    DeleteBulkAction::make()->authorizeIndividualRecords('delete'),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    /** @param  list<string>  $visibleFrom */
    private static function statusAction(string $method, string $label, string $icon, string $color, array $visibleFrom): Action
    {
        return Action::make($method)->label($label)->icon($icon)->color($color)
            ->requiresConfirmation()
            ->authorize('update')
            ->visible(fn (Product $record) => ! $record->trashed() && in_array($record->status, $visibleFrom, true))
            ->action(function (Product $record) use ($method, $label) {
                app(ContentPublisher::class)->{$method}($record, auth()->user());
                Notification::make()->success()->title($label.': '.$record->name)->send();
            });
    }

    private static function bulkStatus(string $name, string $label, string $method): BulkAction
    {
        return BulkAction::make($name)->label($label)
            ->requiresConfirmation()
            ->authorizeIndividualRecords('update')
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records) use ($method) {
                $publisher = app(ContentPublisher::class);
                $records->each(fn (Product $product) => $publisher->{$method}($product, auth()->user()));
                Notification::make()->success()->title($records->count().' product(s) updated')->send();
            });
    }
}
