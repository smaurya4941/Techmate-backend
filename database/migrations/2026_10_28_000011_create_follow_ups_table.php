<?php

use App\Support\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->dateTime('due_at');
            // 'overdue' is derived (pending AND due_at < now), never stored.
            $table->string('status', 20)->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['assigned_to', 'status', 'due_at']);
            $table->index(['status', 'due_at']);
            $table->index(['lead_id', 'status']);
        });

        SchemaChecks::in('follow_ups', 'status', ['pending', 'completed', 'cancelled']);
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_ups');
    }
};
