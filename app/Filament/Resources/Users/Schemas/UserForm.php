<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use App\Rules\PhoneNumber;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Staff account')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('email')->email()->required()->maxLength(255)->unique(ignoreRecord: true),
                TextInput::make('phone')->tel()->rule(new PhoneNumber)->maxLength(30),
                TextInput::make('password')->password()->revealable()
                    ->required(fn (string $operation) => $operation === 'create')
                    ->rule(Password::min(12)->letters()->mixedCase()->numbers())
                    ->autocomplete('new-password')
                    ->helperText(fn (string $operation) => $operation === 'edit' ? 'Leave blank to keep the current password.' : 'At least 12 characters with upper, lower case and a number.'),
            ]),
            Section::make('Access')->columns(2)->schema([
                Select::make('role')->options(User::ROLE_LABELS)->required()->native(false)
                    ->disabled(fn (?User $record) => $record?->is(auth()->user()))
                    ->dehydrated(fn (?User $record) => ! $record?->is(auth()->user()))
                    ->helperText('You cannot change your own role.'),
                Toggle::make('is_active')->label('Active (can sign in)')->default(true)
                    ->disabled(fn (?User $record) => $record?->is(auth()->user()))
                    ->dehydrated(fn (?User $record) => ! $record?->is(auth()->user())),
            ]),
        ]);
    }
}
