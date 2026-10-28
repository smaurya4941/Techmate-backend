<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Filament\Support\Uploads;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ImagesRelationManager extends RelationManager
{
    protected static string $relationship = 'images';

    protected static ?string $title = 'Gallery';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Uploads::image('image_path', 'products/gallery')->label('Image')->required()->columnSpanFull(),
            TextInput::make('alt_text')->label('Alt text')->required()->maxLength(255)
                ->helperText('Describe the picture for screen readers and search engines.'),
            TextInput::make('caption')->maxLength(255),
            TextInput::make('sort_order')->label('Display order')->numeric()->minValue(0)->default(0)->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('alt_text')
            ->columns([
                ImageColumn::make('image_path')->label('')->disk('public')->size(56),
                TextColumn::make('alt_text')->label('Alt text')->wrap(),
                TextColumn::make('caption')->placeholder('-'),
                TextColumn::make('sort_order')->label('Order')->sortable(),
            ])
            // Deterministic order: explicit sort_order, then id.
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->headerActions([CreateAction::make()])
            ->recordActions([
                Action::make('makePrimary')
                    ->label('Set as primary')
                    ->icon('heroicon-o-star')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->authorize('update')
                    ->visible(fn ($record) => $this->getOwnerRecord()->primary_image_path !== $record->image_path)
                    ->action(function ($record) {
                        $this->getOwnerRecord()->update(['primary_image_path' => $record->image_path]);
                        Notification::make()->success()->title('Primary image updated')->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([DeleteBulkAction::make()->authorizeIndividualRecords('delete')]);
    }
}
