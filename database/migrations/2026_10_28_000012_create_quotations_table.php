<?php

use App\Support\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->id();
            $table->string('quotation_number', 32)->unique();
            // RESTRICT: a lead with quotations can never be hard-deleted.
            $table->foreignId('lead_id')->constrained('leads')->restrictOnDelete();
            $table->string('status', 20)->default('draft');
            $table->char('currency_code', 3);
            $table->date('issue_date');
            $table->date('valid_until')->nullable();
            // Money: DECIMAL(14,2); every line is rounded half-up to 2 dp before summing.
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);
            $table->string('customer_name_snapshot', 150);
            $table->string('company_name_snapshot', 200)->nullable();
            $table->text('billing_address_snapshot')->nullable();
            $table->string('customer_email_snapshot', 255)->nullable();
            $table->string('customer_phone_snapshot', 30)->nullable();
            $table->text('terms_and_conditions')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();

            $table->index(['lead_id', 'status']);
            $table->index(['status', 'valid_until']);
        });

        SchemaChecks::in('quotations', 'status', ['draft', 'sent', 'accepted', 'rejected', 'expired', 'cancelled']);
        SchemaChecks::add('quotations', 'amounts_non_negative', '`subtotal` >= 0 AND `discount_total` >= 0 AND `tax_total` >= 0 AND `grand_total` >= 0');
        SchemaChecks::add('quotations', 'grand_total_reconciles', '`grand_total` = `subtotal` - `discount_total` + `tax_total`');
        SchemaChecks::add('quotations', 'valid_until_after_issue', '`valid_until` IS NULL OR `valid_until` >= `issue_date`');
        SchemaChecks::add('quotations', 'has_contact_snapshot', '`customer_email_snapshot` IS NOT NULL OR `customer_phone_snapshot` IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('quotations');
    }
};
