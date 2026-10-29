<?php

namespace App\Filament\Resources\Quotations\Schemas;

use App\Models\Product;
use App\Support\QuotationMath;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

/**
 * Draft editor. The totals shown here are a live preview computed with the same
 * QuotationMath the server uses; the saved figures are always recalculated in
 * QuotationService, so nothing typed or tampered with here can change a total.
 */
class QuotationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Offer')->columns(3)->schema([
                TextInput::make('currency_code')->label('Currency')->required()->length(3)
                    ->default(config('techmate.sales.default_currency'))
                    ->dehydrateStateUsing(fn ($state) => strtoupper((string) $state))
                    ->helperText('ISO code, e.g. INR, USD.'),
                DatePicker::make('issue_date')->required()->default(now()->toDateString())->native(false),
                DatePicker::make('valid_until')->native(false)->afterOrEqual('issue_date')
                    ->helperText('Left empty, the offer is valid for '.config('techmate.sales.quotation_valid_days').' days from the issue date.'),
                Textarea::make('billing_address_snapshot')->label('Billing address')->rows(2)->maxLength(1000)->columnSpanFull(),
            ]),
            Section::make('Line items')->description('Prices are the commercial offer. They are copied into the quotation and never follow later catalogue changes.')->schema([
                Repeater::make('items')->hiddenLabel()->live()
                    ->minItems(1)->maxItems(100)->defaultItems(1)->reorderable()->addActionLabel('Add line')
                    ->itemLabel(fn (array $state) => $state['product_name_snapshot'] ?? null)
                    ->columns(12)
                    ->schema([
                        Select::make('product_id')->label('Catalogue product')->columnSpan(5)
                            ->searchable()->native(false)->live()
                            ->getSearchResultsUsing(fn (string $search) => Product::query()
                                ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"))
                                ->orderBy('name')->limit(25)->get()->mapWithKeys(fn (Product $p) => [$p->id => "{$p->name} ({$p->sku})"])->all())
                            ->getOptionLabelUsing(fn ($value) => ($p = Product::withTrashed()->find($value)) ? "{$p->name} ({$p->sku})" : null)
                            ->afterStateUpdated(function ($state, Set $set) {
                                $product = $state ? Product::query()->find($state) : null;
                                if (! $product) {
                                    return;
                                }
                                $set('product_name_snapshot', $product->name);
                                $set('product_code_snapshot', $product->sku);
                                $set('description_snapshot', $product->short_description);
                                if ($product->price !== null) {
                                    $set('unit_price', (string) $product->price);
                                }
                            })
                            ->helperText('Optional. Leave empty for a custom line.'),
                        TextInput::make('product_name_snapshot')->label('Item name')->maxLength(200)->columnSpan(5)->live(onBlur: true),
                        TextInput::make('product_code_snapshot')->label('Model / SKU')->maxLength(64)->columnSpan(2),
                        Textarea::make('description_snapshot')->label('Description')->rows(2)->maxLength(2000)->columnSpan(12),
                        TextInput::make('quantity')->numeric()->minValue(1)->integer()->default(1)->required()->live(onBlur: true)->columnSpan(2),
                        TextInput::make('unit_price')->label('Unit price')->numeric()->minValue(0)->required()->live(onBlur: true)->columnSpan(3),
                        TextInput::make('discount_amount')->label('Discount (whole line)')->numeric()->minValue(0)->default(0)->live(onBlur: true)->columnSpan(3),
                        TextInput::make('tax_rate')->label('Tax %')->numeric()->minValue(0)->maxValue(100)
                            ->default(config('techmate.sales.default_tax_rate'))->live(onBlur: true)->columnSpan(2),
                        Placeholder::make('line_total')->label('Line total')->columnSpan(2)
                            ->content(fn (Get $get) => self::linePreview($get)),
                    ]),
            ]),
            Section::make('Charges & totals')->columns(2)->schema([
                TextInput::make('freight_amount')->label('Freight / other charges')->numeric()->minValue(0)->default(0)->live(onBlur: true)
                    ->helperText('Added after tax and not taxed.'),
                Placeholder::make('totals')->label('Totals (preview)')
                    ->content(fn (Get $get) => self::totalsPreview($get)),
            ]),
            Section::make('Commercial terms')->columns(2)->collapsible()->schema([
                Textarea::make('payment_terms')->rows(3)->maxLength(2000),
                Textarea::make('delivery_terms')->rows(3)->maxLength(2000),
                Textarea::make('warranty_terms')->rows(3)->maxLength(2000),
                Textarea::make('terms_and_conditions')->label('Other terms & conditions')->rows(3)->maxLength(5000),
                Textarea::make('notes')->label('Notes (printed on the quotation)')->rows(2)->maxLength(2000)->columnSpanFull(),
            ]),
        ]);
    }

    private static function linePreview(Get $get): string
    {
        try {
            $line = QuotationMath::line((int) $get('quantity'), (string) ($get('unit_price') ?: '0'), (string) ($get('discount_amount') ?: '0'), (string) ($get('tax_rate') ?: '0'));

            return number_format((float) $line['line_total'], 2);
        } catch (\Throwable) {
            return '-';
        }
    }

    private static function totalsPreview(Get $get): HtmlString|string
    {
        try {
            $lines = [];
            foreach ((array) $get('items') as $item) {
                if (! isset($item['quantity']) || ($item['unit_price'] ?? '') === '') {
                    continue;
                }
                $discount = (string) ($item['discount_amount'] ?: '0');
                $line = QuotationMath::line((int) $item['quantity'], (string) $item['unit_price'], $discount, (string) ($item['tax_rate'] ?: '0'));
                $lines[] = $line + ['discount_amount' => $discount];
            }

            $t = QuotationMath::totals($lines, (string) ($get('freight_amount') ?: '0'));
            $row = fn (string $label, string $value, bool $bold = false) => '<div style="display:flex;justify-content:space-between;gap:2rem;'.($bold ? 'font-weight:600;border-top:1px solid #cbd5e1;padding-top:.25rem;margin-top:.25rem;' : '').'"><span>'.e($label).'</span><span>'.e($value).'</span></div>';

            return new HtmlString(
                $row('Subtotal', number_format((float) $t['subtotal'], 2))
                .$row('Discount', '- '.number_format((float) $t['discount_total'], 2))
                .$row('Tax', number_format((float) $t['tax_total'], 2))
                .$row('Freight', number_format((float) $t['freight_amount'], 2))
                .$row('Grand total', number_format((float) $t['grand_total'], 2), true)
            );
        } catch (\Throwable) {
            return 'Complete the line items to see totals.';
        }
    }
}
