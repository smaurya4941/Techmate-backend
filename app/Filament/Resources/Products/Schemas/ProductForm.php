<?php

namespace App\Filament\Resources\Products\Schemas;

use App\Filament\Support\Fields;
use App\Filament\Support\Uploads;
use App\Models\Product;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class ProductForm
{
    public const CURRENCIES = ['INR' => 'INR', 'USD' => 'USD', 'EUR' => 'EUR', 'GBP' => 'GBP', 'AED' => 'AED'];

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Basic information')->columns(2)->schema([
                Fields::slugFrom(TextInput::make('name')->required()->maxLength(200)),
                Fields::slug(220),
                TextInput::make('sku')->label('SKU / product code')->required()->maxLength(64)
                    ->regex('/^[A-Za-z0-9][A-Za-z0-9._\-\/ ]*$/')
                    ->unique(ignoreRecord: true),
                TextInput::make('sort_order')->label('Display order')->numeric()->minValue(0)->default(0)->required(),
                Toggle::make('is_featured')->label('Featured product'),
            ]),
            Section::make('Category & brand')->columns(2)->schema([
                Select::make('category_id')->label('Category')
                    ->relationship('category', 'name', fn (Builder $query) => $query->withoutTrashed()->where('is_active', true)->orderBy('name'))
                    ->searchable()->preload()->required(),
                Select::make('brand_id')->label('Brand')
                    ->relationship('brand', 'name', fn (Builder $query) => $query->withoutTrashed()->where('is_active', true)->orderBy('name'))
                    ->searchable()->preload()
                    ->placeholder('No brand'),
            ]),
            Section::make('Descriptions')->schema([
                Textarea::make('short_description')->rows(3)->maxLength(500)
                    ->helperText('Shown on product cards and in search results.'),
                Textarea::make('description')->label('Full description')->rows(8)->maxLength(20000)
                    ->helperText('Plain text. Line breaks are preserved by the website.'),
            ]),
            Section::make('Technical details')
                ->description('Shown on the product card and the product page.')
                ->columns(2)->schema([
                    Select::make('power_source')->options(Product::POWER_SOURCES)->native(false)
                        ->placeholder('Not specified'),
                    TextInput::make('warranty_period')->maxLength(120)
                        ->helperText('For example: 1 year parts and labour. Leave blank if not confirmed.'),
                    Repeater::make('key_highlights')->label('Key highlights')
                        ->simple(TextInput::make('highlight')->required()->maxLength(160))
                        ->defaultItems(0)->addActionLabel('Add highlight')->maxItems(8)->reorderable()->columnSpanFull()
                        ->helperText('Short bullet points, for example "6 trays (600x400 mm)". Up to 8.'),
                ]),
            Section::make('Pricing')
                ->description('Techmate is quote-first: leave blank to show "Request a quote" instead of a price.')
                ->columns(2)->collapsible()->schema([
                    TextInput::make('price')->numeric()->minValue(0)->maxValue(9999999999.99)->step('0.01')
                        ->rules(['decimal:0,2']),
                    Select::make('currency_code')->label('Currency')->options(self::CURRENCIES)
                        ->requiredWith('price')
                        ->helperText('Required when a price is entered.'),
                ]),
            Section::make('Primary image')
                ->description('Main catalogue image. Add more pictures in the Gallery tab after saving.')
                ->schema([
                    Uploads::image('primary_image_path', 'products/primary', 3072, '4:3')->label('Primary image (4:3)'),
                ]),
            Section::make('Publication')->columns(2)->schema([
                Select::make('status')->options([
                    Product::STATUS_DRAFT => 'Draft (hidden)',
                    Product::STATUS_PUBLISHED => 'Published (visible on the website)',
                    Product::STATUS_ARCHIVED => 'Archived (hidden)',
                ])->default(Product::STATUS_DRAFT)->required()->native(false)
                    ->helperText('Only published products appear on the website and in the public API.'),
            ]),
        ]);
    }
}
