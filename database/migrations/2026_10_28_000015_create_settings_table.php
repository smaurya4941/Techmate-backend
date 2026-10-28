<?php

use App\Support\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->string('value_type', 10)->default('string');
            $table->string('group', 50)->nullable();
            // Private by default; never store secrets here regardless of this flag.
            $table->boolean('is_public')->default(false);
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->index(['is_public', 'group']);
        });

        SchemaChecks::in('settings', 'value_type', ['string', 'number', 'boolean', 'json']);
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
