<?php

namespace App\Filament\Resources\Categories\Schemas;

use App\Filament\Support\Fields;
use App\Filament\Support\Uploads;
use App\Models\Category;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Category')->columns(2)->schema([
                Fields::slugFrom(TextInput::make('name')->required()->maxLength(150)),
                Fields::slug(),
                Select::make('parent_id')
                    ->label('Parent category')
                    ->placeholder('None (top-level category)')
                    // The public catalogue is two levels deep: only top-level categories can be parents.
                    ->options(fn (?Category $record) => Category::query()
                        ->whereNull('parent_id')
                        ->when($record, fn ($q) => $q->whereKeyNot($record->getKey()))
                        ->orderBy('name')
                        ->pluck('name', 'id'))
                    ->searchable()
                    ->rule(fn (?Category $record): Closure => self::parentRule($record)),
                Uploads::image('image_path', 'categories', 3072)->label('Image'),
                Textarea::make('description')->rows(4)->maxLength(2000)->columnSpanFull(),
            ]),
            Section::make('Visibility & SEO')->columns(2)->schema([
                Toggle::make('is_active')->label('Active (shown on the website)')->default(true),
                TextInput::make('sort_order')->label('Display order')->numeric()->minValue(0)->default(0)->required(),
                TextInput::make('meta_title')->maxLength(160),
                TextInput::make('meta_description')->maxLength(320),
            ]),
        ]);
    }

    /** Rejects self-parenting, nested parents and moving a category that has children beneath another. */
    public static function parentRule(?Category $record): Closure
    {
        return function (string $attribute, $value, Closure $fail) use ($record) {
            if (blank($value)) {
                return;
            }

            $parent = Category::withTrashed()->find($value);

            if (! $parent) {
                $fail('The selected parent does not exist.');
            } elseif ($record && $parent->getKey() === $record->getKey()) {
                $fail('A category cannot be its own parent.');
            } elseif ($parent->parent_id !== null) {
                $fail('Categories can only be nested one level deep.');
            } elseif ($record && $record->children()->withTrashed()->exists()) {
                $fail('This category has sub-categories, so it cannot become a sub-category itself.');
            }
        };
    }
}
