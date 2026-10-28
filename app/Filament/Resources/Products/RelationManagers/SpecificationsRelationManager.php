<?php

namespace App\Filament\Resources\Products\RelationManagers;

use App\Models\ProductSpecification;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

class SpecificationsRelationManager extends RelationManager
{
    protected static string $relationship = 'specifications';

    protected static ?string $title = 'Technical specifications';

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('specification_group')->label('Group')->maxLength(100)
                ->placeholder('e.g. Electrical')->datalist(fn () => $this->getOwnerRecord()->specifications()
                ->whereNotNull('specification_group')->distinct()->pluck('specification_group')->all()),
            TextInput::make('specification_label')->label('Label')->required()->maxLength(150),
            TextInput::make('specification_key')->label('Key')->required()->maxLength(100)
                ->regex('/^[a-z0-9_]+$/')
                ->validationMessages(['regex' => 'Lowercase letters, numbers and underscores only.'])
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('product_id', $this->getOwnerRecord()->getKey()))
                ->helperText('Stable identifier used by the API, e.g. power_kw.'),
            Select::make('value_type')->label('Value type')->options([
                ProductSpecification::TYPE_STRING => 'Text',
                ProductSpecification::TYPE_NUMBER => 'Number',
                ProductSpecification::TYPE_BOOLEAN => 'Yes / No',
            ])->default(ProductSpecification::TYPE_STRING)->required()->live()->native(false),
            TextInput::make('value_string')->label('Value')->maxLength(500)
                ->required(fn (Get $get) => $get('value_type') === ProductSpecification::TYPE_STRING)
                ->visible(fn (Get $get) => $get('value_type') === ProductSpecification::TYPE_STRING),
            TextInput::make('value_number')->label('Value')->numeric()
                ->required(fn (Get $get) => $get('value_type') === ProductSpecification::TYPE_NUMBER)
                ->visible(fn (Get $get) => $get('value_type') === ProductSpecification::TYPE_NUMBER),
            Toggle::make('value_boolean')->label('Value')
                ->visible(fn (Get $get) => $get('value_type') === ProductSpecification::TYPE_BOOLEAN),
            TextInput::make('unit')->maxLength(20)->placeholder('e.g. kW, mm, kg'),
            TextInput::make('sort_order')->label('Display order')->numeric()->minValue(0)->default(0)->required(),
        ]);
    }

    /** Only the column matching value_type may be populated (the model and MySQL CHECK enforce it). */
    public static function normalise(array $data): array
    {
        $columns = ['string' => 'value_string', 'number' => 'value_number', 'boolean' => 'value_boolean'];

        foreach ($columns as $type => $column) {
            if ($type !== ($data['value_type'] ?? null)) {
                $data[$column] = null;
            }
        }

        if (($data['value_type'] ?? null) === 'boolean') {
            $data['value_boolean'] = (bool) ($data['value_boolean'] ?? false);
        }

        return $data;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('specification_label')
            ->columns([
                TextColumn::make('specification_group')->label('Group')->placeholder('General')->sortable(),
                TextColumn::make('specification_label')->label('Label')->searchable(),
                TextColumn::make('display_value')->label('Value')
                    ->state(fn (ProductSpecification $record) => match ($record->value_type) {
                        'boolean' => $record->value_boolean ? 'Yes' : 'No',
                        'number' => rtrim(rtrim((string) $record->value_number, '0'), '.'),
                        default => $record->value_string,
                    }),
                TextColumn::make('unit')->placeholder('-'),
                TextColumn::make('sort_order')->label('Order')->sortable(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->headerActions([CreateAction::make()->mutateDataUsing(fn (array $data) => self::normalise($data))])
            ->recordActions([
                EditAction::make()->mutateDataUsing(fn (array $data) => self::normalise($data)),
                DeleteAction::make(),
            ])
            ->toolbarActions([DeleteBulkAction::make()->authorizeIndividualRecords('delete')]);
    }
}
