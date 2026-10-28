<?php

use App\Support\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('title', 200);
            $table->string('document_type', 20);
            $table->string('file_path');
            $table->string('original_filename', 255)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->string('language_code', 10)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            // Private by default: documents are public only when explicitly flagged.
            $table->boolean('is_public')->default(false);
            $table->timestamps();

            $table->index(['product_id', 'is_public', 'sort_order']);
        });

        SchemaChecks::in('product_documents', 'document_type', ['brochure', 'datasheet', 'manual', 'certificate', 'other']);
    }

    public function down(): void
    {
        Schema::dropIfExists('product_documents');
    }
};
