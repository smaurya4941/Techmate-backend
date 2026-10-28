<?php

namespace App\Filament\Support;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;

class Fields
{
    /**
     * Slug input that is generated from the source field on create only.
     * Editing a record never rewrites its slug automatically, so public URLs
     * only change when staff change them deliberately; duplicates (including
     * archived rows, which still hold the unique index) fail validation.
     */
    public static function slug(int $maxLength = 160, string $source = 'name'): TextInput
    {
        return TextInput::make('slug')
            ->required()
            ->maxLength($maxLength)
            ->regex('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
            ->validationMessages(['regex' => 'Use lowercase letters, numbers and single hyphens only.'])
            ->unique(ignoreRecord: true)
            ->helperText('Part of the public URL. Changing it on an existing record changes its web address.');
    }

    /** Wire onto the source field (name/title) so the slug follows it while creating. */
    public static function slugFrom(TextInput $input): TextInput
    {
        return $input
            ->live(onBlur: true)
            ->afterStateUpdated(function (string $operation, ?string $state, Get $get, Set $set) {
                if ($operation === 'create' && blank($get('slug'))) {
                    $set('slug', Str::slug((string) $state));
                }
            });
    }
}
