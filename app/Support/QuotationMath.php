<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The single documented quotation calculation method (see docs/database-schema.md).
 *
 * Per line (all amounts in the quotation currency, 2 decimal places):
 *   gross         = quantity x unit_price
 *   line_subtotal = gross - discount_amount        (fixed amount for the whole line)
 *   tax_amount    = round_half_up(line_subtotal x tax_rate / 100, 2)
 *   line_total    = line_subtotal + tax_amount
 *
 * Header, summed from already-rounded lines:
 *   subtotal       = sum(gross)
 *   discount_total = sum(discount_amount)
 *   tax_total      = sum(tax_amount)
 *   grand_total    = subtotal - discount_total + tax_total
 *
 * Values are strings so no float ever touches money.
 */
class QuotationMath
{
    /**
     * @return array{line_subtotal: string, tax_amount: string, line_total: string, gross: string}
     */
    public static function line(int $quantity, string $unitPrice, string $discountAmount = '0', string $taxRate = '0'): array
    {
        if ($quantity <= 0) {
            throw new \InvalidArgumentException('Quantity must be a positive integer.');
        }

        $gross = BigDecimal::of($unitPrice)->multipliedBy($quantity)->toScale(2, RoundingMode::HALF_UP);
        $discount = BigDecimal::of($discountAmount)->toScale(2, RoundingMode::HALF_UP);

        if ($discount->isNegative() || $discount->isGreaterThan($gross)) {
            throw new \InvalidArgumentException('Discount must be between 0 and the line gross amount.');
        }

        $rate = BigDecimal::of($taxRate);
        if ($rate->isNegative() || $rate->isGreaterThan(100)) {
            throw new \InvalidArgumentException('Tax rate must be between 0 and 100.');
        }

        $subtotal = $gross->minus($discount);
        $tax = $subtotal->multipliedBy($rate)->dividedBy(100, 2, RoundingMode::HALF_UP);

        return [
            'gross' => (string) $gross,
            'line_subtotal' => (string) $subtotal,
            'tax_amount' => (string) $tax,
            'line_total' => (string) $subtotal->plus($tax),
        ];
    }

    /**
     * @param  list<array{gross: string, discount_amount: string, tax_amount: string}>  $lines
     * @return array{subtotal: string, discount_total: string, tax_total: string, grand_total: string}
     */
    public static function totals(array $lines): array
    {
        $subtotal = BigDecimal::zero()->toScale(2);
        $discount = BigDecimal::zero()->toScale(2);
        $tax = BigDecimal::zero()->toScale(2);

        foreach ($lines as $line) {
            $subtotal = $subtotal->plus($line['gross']);
            $discount = $discount->plus($line['discount_amount']);
            $tax = $tax->plus($line['tax_amount']);
        }

        return [
            'subtotal' => (string) $subtotal,
            'discount_total' => (string) $discount,
            'tax_total' => (string) $tax,
            'grand_total' => (string) $subtotal->minus($discount)->plus($tax),
        ];
    }
}
