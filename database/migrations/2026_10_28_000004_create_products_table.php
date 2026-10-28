<?php

use App\Support\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            // RESTRICT: a category that still has products cannot be hard-deleted.
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            // SET NULL: products survive a brand being removed.
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->string('name', 200);
            $table->string('slug', 220)->unique();
            $table->string('sku', 64)->unique();
            $table->string('short_description', 500)->nullable();
            $table->longText('description')->nullable();
            // Quote-first B2B: price is optional and indicative only.
            $table->decimal('price', 12, 2)->nullable();
            $table->char('currency_code', 3)->nullable();
            $table->string('primary_image_path')->nullable();
            $table->string('status', 20)->default('draft');
            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Category browsing (also serves the category_id foreign key).
            $table->index(['category_id', 'status', 'sort_order']);
            // Brand filter (also serves the brand_id foreign key).
            $table->index(['brand_id', 'status']);
            // Homepage / featured listings.
            $table->index(['is_featured', 'status', 'sort_order']);
            // Latest published listings.
            $table->index(['status', 'published_at']);
        });

        SchemaChecks::in('products', 'status', ['draft', 'published', 'archived']);
        SchemaChecks::add('products', 'price_non_negative', '`price` IS NULL OR `price` >= 0');
        SchemaChecks::add('products', 'price_has_currency', '`price` IS NULL OR `currency_code` IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
