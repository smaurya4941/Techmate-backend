<?php

namespace Tests\Unit;

use App\Support\QuotationMath;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class QuotationMathTest extends TestCase
{
    public function test_line_is_rounded_half_up_to_two_decimals(): void
    {
        // 3 x 19.99 = 59.97; 7.5% of 59.97 = 4.49775 -> 4.50
        $line = QuotationMath::line(3, '19.99', '0', '7.5');

        $this->assertSame('59.97', $line['gross']);
        $this->assertSame('59.97', $line['line_subtotal']);
        $this->assertSame('4.50', $line['tax_amount']);
        $this->assertSame('64.47', $line['line_total']);
    }

    public function test_discount_is_a_fixed_line_amount_applied_before_tax(): void
    {
        $line = QuotationMath::line(2, '100.00', '50.00', '10');

        $this->assertSame('150.00', $line['line_subtotal']);
        $this->assertSame('15.00', $line['tax_amount']);
        $this->assertSame('165.00', $line['line_total']);
    }

    public function test_header_totals_reconcile_with_lines(): void
    {
        $a = QuotationMath::line(2, '100.00', '50.00', '10');
        $b = QuotationMath::line(1, '19.99', '0', '0');

        $totals = QuotationMath::totals([
            ['gross' => $a['gross'], 'discount_amount' => '50.00', 'tax_amount' => $a['tax_amount']],
            ['gross' => $b['gross'], 'discount_amount' => '0', 'tax_amount' => $b['tax_amount']],
        ]);

        $this->assertSame('219.99', $totals['subtotal']);
        $this->assertSame('50.00', $totals['discount_total']);
        $this->assertSame('15.00', $totals['tax_total']);
        $this->assertSame('184.99', $totals['grand_total']);
        // 165.00 + 19.99: the sum of the line totals
        $this->assertSame([$a['line_total'], $b['line_total']], ['165.00', '19.99']);
    }

    public function test_invalid_inputs_are_rejected(): void
    {
        foreach ([
            [0, '10.00', '0', '0'],
            [1, '10.00', '11.00', '0'],
            [1, '10.00', '-1', '0'],
            [1, '10.00', '0', '101'],
        ] as $args) {
            try {
                QuotationMath::line(...$args);
                $this->fail('Expected InvalidArgumentException for '.json_encode($args));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
