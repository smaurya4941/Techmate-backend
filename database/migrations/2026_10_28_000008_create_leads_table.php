<?php

use App\Support\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('public_reference', 24)->unique();
            $table->string('lead_type', 20)->default('general');
            $table->string('customer_name', 150);
            $table->string('company_name', 200)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('alternate_phone', 30)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('subject', 200)->nullable();
            $table->text('message')->nullable();
            $table->string('source', 50)->nullable();
            $table->string('status', 20)->default('new');
            $table->string('priority', 10)->default('normal');
            // SET NULL: removing staff never deletes a lead.
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_contacted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('consent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['assigned_to', 'status']);
            $table->index(['lead_type', 'status']);
            $table->index('email');
            $table->index('created_at');
        });

        SchemaChecks::in('leads', 'lead_type', ['general', 'product', 'quotation']);
        SchemaChecks::in('leads', 'status', ['new', 'contacted', 'qualified', 'proposal', 'won', 'lost', 'spam']);
        SchemaChecks::in('leads', 'priority', ['low', 'normal', 'high', 'urgent']);
        // B2B enquiries may omit one channel, but must be reachable somehow.
        SchemaChecks::add('leads', 'has_contact_channel', '`email` IS NOT NULL OR `phone` IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
