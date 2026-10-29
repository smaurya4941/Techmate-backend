<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('power_source', 20)->nullable()->after('currency_code');
            $table->string('warranty_period', 120)->nullable()->after('power_source');
            // Short bullet points shown on the product card and page.
            $table->json('key_highlights')->nullable()->after('warranty_period');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['power_source', 'warranty_period', 'key_highlights']);
        });
    }
};
