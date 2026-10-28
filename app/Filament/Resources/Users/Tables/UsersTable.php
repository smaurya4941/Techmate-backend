<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->sortable(),
                TextColumn::make('role')->badge()->formatStateUsing(fn ($state) => User::ROLE_LABELS[$state] ?? $state)->sortable(),
                IconColumn::make('is_active')->label('Active')->boolean()->sortable(),
                TextColumn::make('last_login_at')->label('Last sign-in')->dateTime('d M Y H:i')->placeholder('Never')->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('role')->options(User::ROLE_LABELS),
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([EditAction::make()]);
    }
}
