<?php

namespace App\Filament\Resources\Brands\Schemas;

use App\Filament\Support\Fields;
use App\Filament\Support\Uploads;
use App\Rules\HttpUrl;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class BrandForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Brand')->columns(2)->schema([
                Fields::slugFrom(TextInput::make('name')->required()->maxLength(150)),
                Fields::slug(),
                TextInput::make('website_url')->label('Website')->url()->rule(new HttpUrl)->maxLength(255)->columnSpanFull(),
                Textarea::make('description')->rows(4)->maxLength(2000)->columnSpanFull(),
                Uploads::image('logo_path', 'brands/logos', 2048)->label('Logo'),
                Section::make()->schema([
                    Toggle::make('is_active')->label('Active (shown on the website)')->default(true),
                    TextInput::make('sort_order')->label('Display order')->numeric()->minValue(0)->default(0)->required(),
                ])->columnSpan(1)->compact(),
            ]),
        ]);
    }
}
