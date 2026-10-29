<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Models\OrderItem;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** The agreed line items, copied from the accepted quotation and never recalculated. */
class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Ordered items';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->paginated(false)
            ->columns([
                TextColumn::make('product_name_snapshot')->label('Item')->description(fn (OrderItem $r) => $r->product_code_snapshot),
                TextColumn::make('quantity')->label('Qty'),
                TextColumn::make('unit_price')->formatStateUsing(fn ($s) => number_format((float) $s, 2)),
                TextColumn::make('discount_amount')->label('Discount')->formatStateUsing(fn ($s) => number_format((float) $s, 2)),
                TextColumn::make('tax_rate')->label('Tax %')->formatStateUsing(fn ($s) => rtrim(rtrim(number_format((float) $s, 2), '0'), '.')),
                TextColumn::make('line_total')->label('Line total')->formatStateUsing(fn ($s) => number_format((float) $s, 2)),
            ]);
    }
}
