<?php

use App\Support\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Distributor applications carry partner-specific data (territory, business
        // focus, experience) and a review workflow that the sales `leads` table
        // does not model, so they get their own table.
        Schema::create('distributor_applications', function (Blueprint $table) {
            $table->id();
            $table->string('public_reference', 24)->unique();
            $table->string('contact_name', 150);
            $table->string('company_name', 200);
            $table->string('email', 255);
            $table->string('phone', 30);
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->char('country_code', 2)->nullable();
            $table->string('website_url', 255)->nullable();
            $table->string('business_type', 30);
            $table->string('experience_range', 10)->nullable();
            $table->json('regions_served');
            $table->json('product_interests')->nullable();
            $table->text('message')->nullable();
            $table->string('status', 20)->default('new');
            // SET NULL: reviewing staff leaving never deletes an application.
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('internal_notes')->nullable();
            $table->timestamp('consent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('email');
        });

        SchemaChecks::in('distributor_applications', 'status', ['new', 'under_review', 'approved', 'rejected', 'withdrawn']);
    }

    public function down(): void
    {
        Schema::dropIfExists('distributor_applications');
    }
};
