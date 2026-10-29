<?php

use App\Support\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 commercial workflow: every revision is its own immutable-once-issued row
 * in a series (series_number + version), approval and acceptance evidence are
 * recorded on the version they apply to, and every transition lands in
 * quotation_events. Freight is a separate, untaxed charge (see QuotationMath).
 */
return new class extends Migration
{
    public const STATUSES = ['draft', 'pending_approval', 'approved', 'sent', 'accepted', 'rejected', 'expired', 'superseded', 'cancelled'];

    public function up(): void
    {
        SchemaChecks::drop('quotations', 'grand_total_reconciles');
        SchemaChecks::drop('quotations', 'amounts_non_negative');

        Schema::table('quotations', function (Blueprint $table) {
            $table->string('series_number', 32)->nullable()->after('quotation_number');
            $table->unsignedSmallInteger('version')->default(1)->after('series_number');
            $table->foreignId('supersedes_id')->nullable()->after('version')->constrained('quotations')->nullOnDelete();
            $table->boolean('is_current')->default(true)->after('supersedes_id');
            $table->decimal('freight_amount', 14, 2)->default(0)->after('tax_total');
            $table->text('payment_terms')->nullable()->after('terms_and_conditions');
            $table->text('delivery_terms')->nullable()->after('payment_terms');
            $table->text('warranty_terms')->nullable()->after('delivery_terms');
            $table->timestamp('submitted_at')->nullable()->after('rejected_at');
            $table->timestamp('approved_at')->nullable()->after('submitted_at');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->text('approval_note')->nullable()->after('approved_by');
            $table->foreignId('sent_by')->nullable()->after('approval_note')->constrained('users')->nullOnDelete();
            $table->string('pdf_path', 255)->nullable()->after('sent_by');
            $table->string('pdf_checksum', 64)->nullable()->after('pdf_path');
            // Acceptance is evidence-based: an e-mail open is never acceptance.
            $table->string('acceptance_method', 30)->nullable()->after('pdf_checksum');
            $table->string('acceptance_reference', 100)->nullable()->after('acceptance_method');
            $table->text('acceptance_note')->nullable()->after('acceptance_reference');
            $table->foreignId('acceptance_recorded_by')->nullable()->after('acceptance_note')->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable()->after('acceptance_recorded_by');
        });

        DB::table('quotations')->update(['series_number' => DB::raw('quotation_number')]);

        Schema::table('quotations', function (Blueprint $table) {
            $table->unique(['series_number', 'version']);
            $table->index(['lead_id', 'is_current']);
        });

        SchemaChecks::replaceIn('quotations', 'status', self::STATUSES);
        SchemaChecks::add('quotations', 'amounts_non_negative', '`subtotal` >= 0 AND `discount_total` >= 0 AND `tax_total` >= 0 AND `freight_amount` >= 0 AND `grand_total` >= 0');
        SchemaChecks::add('quotations', 'grand_total_reconciles', '`grand_total` = `subtotal` - `discount_total` + `tax_total` + `freight_amount`');
        SchemaChecks::in('quotations', 'acceptance_method', ['signed_acceptance', 'purchase_order', 'email_confirmation', 'advance_payment']);

        Schema::create('quotation_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quotation_id')->constrained('quotations')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 30);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->text('note')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->useCurrent();

            $table->index(['quotation_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_events');

        SchemaChecks::drop('quotations', 'acceptance_method_valid');
        SchemaChecks::drop('quotations', 'grand_total_reconciles');
        SchemaChecks::drop('quotations', 'amounts_non_negative');
        SchemaChecks::drop('quotations', 'status_valid');

        // Lossy by nature: the old schema has no freight column, so it is folded out of the total.
        DB::statement('UPDATE quotations SET grand_total = grand_total - freight_amount, freight_amount = 0 WHERE freight_amount > 0');
        DB::table('quotations')->whereIn('status', ['pending_approval', 'approved'])->update(['status' => 'draft']);
        DB::table('quotations')->where('status', 'superseded')->update(['status' => 'cancelled']);

        Schema::table('quotations', function (Blueprint $table) {
            $table->dropUnique(['series_number', 'version']);
            $table->dropIndex(['lead_id', 'is_current']);
            $table->dropForeign(['supersedes_id']);
            $table->dropForeign(['approved_by']);
            $table->dropForeign(['sent_by']);
            $table->dropForeign(['acceptance_recorded_by']);
            $table->dropColumn([
                'series_number', 'version', 'supersedes_id', 'is_current', 'freight_amount', 'payment_terms',
                'delivery_terms', 'warranty_terms', 'submitted_at', 'approved_at', 'approved_by', 'approval_note',
                'sent_by', 'pdf_path', 'pdf_checksum', 'acceptance_method', 'acceptance_reference',
                'acceptance_note', 'acceptance_recorded_by', 'rejection_reason',
            ]);
        });

        SchemaChecks::in('quotations', 'status', ['draft', 'sent', 'accepted', 'rejected', 'expired', 'cancelled']);
        SchemaChecks::add('quotations', 'amounts_non_negative', '`subtotal` >= 0 AND `discount_total` >= 0 AND `tax_total` >= 0 AND `grand_total` >= 0');
        SchemaChecks::add('quotations', 'grand_total_reconciles', '`grand_total` = `subtotal` - `discount_total` + `tax_total`');
    }
};
