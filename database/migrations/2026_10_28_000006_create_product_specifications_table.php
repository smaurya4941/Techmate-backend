<?php

use App\Support\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_specifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('specification_group', 100)->nullable();
            $table->string('specification_key', 100);
            $table->string('specification_label', 150);
            $table->string('value_type', 10);
            $table->string('value_string', 500)->nullable();
            $table->decimal('value_number', 14, 4)->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->string('unit', 20)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            // A key appears once per product regardless of group.
            $table->unique(['product_id', 'specification_key']);
            $table->index(['product_id', 'sort_order']);
        });

        SchemaChecks::in('product_specifications', 'value_type', ['string', 'number', 'boolean']);
        // Exactly the column matching value_type is populated.
        SchemaChecks::add('product_specifications', 'value_matches_type', "(
            (`value_type` = 'string' AND `value_string` IS NOT NULL AND `value_number` IS NULL AND `value_boolean` IS NULL)
            OR (`value_type` = 'number' AND `value_number` IS NOT NULL AND `value_string` IS NULL AND `value_boolean` IS NULL)
            OR (`value_type` = 'boolean' AND `value_boolean` IS NOT NULL AND `value_string` IS NULL AND `value_number` IS NULL)
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('product_specifications');
    }
};
