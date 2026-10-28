<?php

use App\Support\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotation_items', function (Blueprint $table) {
            $table->id();
            // CASCADE from the header only; quotations themselves are protected by leads RESTRICT.
            $table->foreignId('quotation_id')->constrained('quotations')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('product_name_snapshot', 200);
            $table->string('product_code_snapshot', 64)->nullable();
            $table->text('description_snapshot')->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            // Fixed amount for the whole line (not per unit, not a percentage).
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('line_subtotal', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['quotation_id', 'sort_order']);
        });

        SchemaChecks::add('quotation_items', 'quantity_positive', '`quantity` > 0');
        SchemaChecks::add('quotation_items', 'amounts_non_negative', '`unit_price` >= 0 AND `discount_amount` >= 0 AND `tax_amount` >= 0 AND `line_subtotal` >= 0 AND `line_total` >= 0');
        SchemaChecks::add('quotation_items', 'tax_rate_range', '`tax_rate` >= 0 AND `tax_rate` <= 100');
        // Exact: integer quantity x 2 dp unit price.
        SchemaChecks::add('quotation_items', 'line_subtotal_reconciles', '`line_subtotal` = `quantity` * `unit_price` - `discount_amount`');
        SchemaChecks::add('quotation_items', 'line_total_reconciles', '`line_total` = `line_subtotal` + `tax_amount`');
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_items');
    }
};
