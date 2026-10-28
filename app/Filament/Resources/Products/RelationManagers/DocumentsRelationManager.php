<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Filament\Support\Uploads;
use App\Models\ProductDocument;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Brochures & datasheets';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Uploads::document('file_path', 'product-documents')->label('File')->required()->columnSpanFull(),
            TextInput::make('title')->required()->maxLength(200),
            Select::make('document_type')->label('Type')
                ->options(collect(ProductDocument::TYPES)->mapWithKeys(fn ($t) => [$t => ucfirst($t)]))
                ->required()->native(false),
            TextInput::make('language_code')->label('Language')->maxLength(10)->placeholder('en'),
            TextInput::make('sort_order')->label('Display order')->numeric()->minValue(0)->default(0)->required(),
            Toggle::make('is_public')->label('Approved for public download')
                ->helperText('Off by default. Only public documents are linked on the website; private ones stay staff-only.')
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('title')->searchable()->wrap(),
                TextColumn::make('document_type')->label('Type')->badge()->formatStateUsing(fn ($state) => ucfirst($state)),
                TextColumn::make('file_size_bytes')->label('Size')->formatStateUsing(fn ($state) => $state ? number_format($state / 1024, 0).' KB' : '-'),
                IconColumn::make('is_public')->label('Public')->boolean(),
                TextColumn::make('sort_order')->label('Order')->sortable(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->headerActions([CreateAction::make()])
            ->recordActions([
                Action::make('download')
                    ->label('Download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->authorize('view')
                    ->url(fn (ProductDocument $record) => route('admin.product-documents.download', $record), shouldOpenInNewTab: true),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([DeleteBulkAction::make()->authorizeIndividualRecords('delete')]);
    }
}
