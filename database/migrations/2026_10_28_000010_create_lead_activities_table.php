<?php

use App\Support\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            // SET NULL: history outlives the staff account.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('activity_type', 20);
            $table->text('description')->nullable();
            $table->string('old_status', 20)->nullable();
            $table->string('new_status', 20)->nullable();
            $table->json('metadata')->nullable();
            // When it happened (may be back-dated); created_at is when it was recorded.
            $table->dateTime('occurred_at');
            $table->timestamps();

            $table->index(['lead_id', 'occurred_at']);
        });

        SchemaChecks::in('lead_activities', 'activity_type', ['note', 'call', 'email', 'meeting', 'status_change', 'assignment']);
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_activities');
    }
};
