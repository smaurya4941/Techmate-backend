<?php

use App\Support\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_products', function (Blueprint $table) {
            $table->id();
            // CASCADE only from the owning lead; leads with quotations cannot be deleted (quotations RESTRICT).
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            // SET NULL: the enquiry survives product removal; snapshots keep the identity.
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('product_name_snapshot', 200);
            $table->string('product_code_snapshot', 64)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->text('customer_notes')->nullable();
            $table->timestamps();
        });

        SchemaChecks::add('lead_products', 'quantity_positive', '`quantity` > 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_products');
    }
};
