<?php

namespace Database\Factories;

use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Support\QuotationMath;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<QuotationItem> */
class QuotationItemFactory extends Factory
{
    public function definition(): array
    {
        return $this->lineAttributes(2, '100.00', '0', '0');
    }

    /** Builds a line whose stored amounts follow QuotationMath. */
    public function line(int $quantity, string $unitPrice, string $discount = '0', string $taxRate = '0'): static
    {
        return $this->state($this->lineAttributes($quantity, $unitPrice, $discount, $taxRate));
    }

    private function lineAttributes(int $quantity, string $unitPrice, string $discount, string $taxRate): array
    {
        $line = QuotationMath::line($quantity, $unitPrice, $discount, $taxRate);

        return [
            'quotation_id' => Quotation::factory(),
            'product_id' => null,
            'product_name_snapshot' => 'Sample Product',
            'product_code_snapshot' => 'SAMPLE-0001',
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'discount_amount' => $discount,
            'tax_rate' => $taxRate,
            'tax_amount' => $line['tax_amount'],
            'line_subtotal' => $line['line_subtotal'],
            'line_total' => $line['line_total'],
            'sort_order' => 0,
        ];
    }
}
