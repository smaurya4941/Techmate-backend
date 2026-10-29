<?php

use App\Support\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 foundation: concurrency-safe reference sequences, reusable customers,
 * the full lead lifecycle, follow-up task states, durable notification outbox
 * and a database-backed audit trail. Existing rows are migrated, never dropped.
 */
return new class extends Migration
{
    public const LEAD_STATUSES = ['new', 'assigned', 'contacted', 'qualified', 'quotation_in_progress', 'quotation_sent', 'negotiation', 'won', 'lost', 'spam'];

    public const FOLLOW_UP_STATUSES = ['open', 'in_progress', 'completed', 'cancelled'];

    public const ACTIVITY_TYPES = ['note', 'call', 'email', 'meeting', 'status_change', 'assignment', 'follow_up', 'quotation', 'outcome', 'order'];

    public const ROLES = ['super_admin', 'catalogue_manager', 'sales_executive', 'sales_manager', 'operations'];

    public function up(): void
    {
        // Gap-free, lock-protected counters for human-readable references.
        Schema::create('sequences', function (Blueprint $table) {
            $table->string('name', 60)->primary();
            $table->unsignedBigInteger('value')->default(0);
            $table->timestamps();
        });

        // Reusable contact records. Policy: one customer per normalised e-mail; every
        // enquiry still becomes its own lead (a returning customer may have a new project).
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('company_name', 200)->nullable();
            $table->string('email', 255)->nullable()->unique();
            $table->string('phone', 30)->nullable();
            $table->timestamps();
        });

        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('public_reference')->constrained('customers')->nullOnDelete();
            // When the visitor's request reached the API vs. created_at (when the lead row was committed).
            $table->timestamp('submitted_at')->nullable()->after('consent_at');
            $table->string('idempotency_key', 64)->nullable()->unique()->after('submitted_at');
            // Approved attribution only: the page path and campaign label, never browsing history.
            $table->string('source_page', 255)->nullable()->after('source');
            $table->string('campaign', 100)->nullable()->after('source_page');
            $table->timestamp('first_response_at')->nullable()->after('last_contacted_at');
            $table->string('lost_reason', 40)->nullable()->after('closed_at');
            $table->text('outcome_notes')->nullable()->after('lost_reason');
            $table->foreignId('closed_by')->nullable()->after('outcome_notes')->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('outcome_quotation_id')->nullable()->after('closed_by');
            $table->index('first_response_at');
        });

        SchemaChecks::drop('leads', 'status_valid');
        // 'quotation_in_progress' is 21 characters: the original VARCHAR(20) would reject it on MySQL.
        Schema::table('leads', function (Blueprint $table) {
            $table->string('status', 30)->default('new')->change();
        });
        Schema::table('lead_activities', function (Blueprint $table) {
            $table->string('old_status', 30)->nullable()->change();
            $table->string('new_status', 30)->nullable()->change();
        });
        DB::table('leads')->where('status', 'proposal')->update(['status' => 'quotation_in_progress']);
        SchemaChecks::in('leads', 'status', self::LEAD_STATUSES);
        SchemaChecks::in('leads', 'lost_reason', ['price_budget', 'competitor', 'specification_mismatch', 'delivery_timeline', 'project_postponed', 'unresponsive', 'other']);

        $this->backfillCustomers();

        // Follow-up tasks: Open / In Progress / Completed / Cancelled, plus reminder bookkeeping.
        SchemaChecks::drop('follow_ups', 'status_valid');
        DB::table('follow_ups')->where('status', 'pending')->update(['status' => 'open']);
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->string('status', 20)->default('open')->change();
            $table->text('completion_note')->nullable()->after('completed_at');
            // Lead time before due_at for the heads-up reminder; null means no reminder.
            $table->unsignedInteger('remind_before_minutes')->nullable()->after('completion_note');
            $table->timestamp('reminder_sent_at')->nullable()->after('remind_before_minutes');
            $table->timestamp('overdue_notified_at')->nullable()->after('reminder_sent_at');
        });
        SchemaChecks::in('follow_ups', 'status', self::FOLLOW_UP_STATUSES);

        SchemaChecks::replaceIn('lead_activities', 'activity_type', self::ACTIVITY_TYPES);
        SchemaChecks::replaceIn('users', 'role', self::ROLES);

        // Durable outbox: a lead commits together with the notifications it owes, so a
        // crash after commit can never lose an e-mail. dedupe_key makes every notification
        // idempotent across retries, duplicate scheduler runs and parallel workers.
        Schema::create('notification_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);
            $table->string('dedupe_key', 120)->unique();
            $table->string('recipient_email', 255);
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->string('status', 15)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->dateTime('available_at');
            $table->dateTime('claimed_at')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at']);
            $table->index(['lead_id', 'type']);
        });
        SchemaChecks::in('notification_outbox', 'status', ['pending', 'sending', 'sent', 'failed']);

        // Append-only business audit trail (who did what to which record, with before/after).
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event', 60);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['event', 'created_at']);
            $table->index('actor_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('notification_outbox');

        // Map rows away from the new values first, then re-add the narrower CHECKs.
        SchemaChecks::drop('users', 'role_valid');
        DB::table('users')->where('role', 'operations')->update(['role' => 'sales_executive']);
        SchemaChecks::in('users', 'role', ['super_admin', 'catalogue_manager', 'sales_executive', 'sales_manager']);

        SchemaChecks::drop('lead_activities', 'activity_type_valid');
        DB::table('lead_activities')->whereIn('activity_type', ['follow_up', 'quotation', 'outcome', 'order'])->update(['activity_type' => 'note']);
        SchemaChecks::in('lead_activities', 'activity_type', ['note', 'call', 'email', 'meeting', 'status_change', 'assignment']);

        SchemaChecks::drop('follow_ups', 'status_valid');
        DB::table('follow_ups')->whereIn('status', ['open', 'in_progress'])->update(['status' => 'pending']);
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->dropColumn(['completion_note', 'remind_before_minutes', 'reminder_sent_at', 'overdue_notified_at']);
            $table->string('status', 20)->default('pending')->change();
        });
        SchemaChecks::in('follow_ups', 'status', ['pending', 'completed', 'cancelled']);

        SchemaChecks::drop('leads', 'lost_reason_valid');
        SchemaChecks::drop('leads', 'status_valid');
        DB::table('leads')->whereIn('status', ['assigned', 'quotation_in_progress', 'quotation_sent', 'negotiation'])
            ->update(['status' => 'proposal']);
        SchemaChecks::in('leads', 'status', ['new', 'contacted', 'qualified', 'proposal', 'won', 'lost', 'spam']);
        DB::table('lead_activities')->whereIn('old_status', ['assigned', 'quotation_in_progress', 'quotation_sent', 'negotiation'])->update(['old_status' => 'proposal']);
        DB::table('lead_activities')->whereIn('new_status', ['assigned', 'quotation_in_progress', 'quotation_sent', 'negotiation'])->update(['new_status' => 'proposal']);

        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropForeign(['closed_by']);
            $table->dropIndex(['first_response_at']);
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn([
                'customer_id', 'submitted_at', 'idempotency_key', 'source_page', 'campaign',
                'first_response_at', 'lost_reason', 'outcome_notes', 'closed_by', 'outcome_quotation_id',
            ]);
        });

        Schema::dropIfExists('customers');
        Schema::dropIfExists('sequences');
    }

    private function backfillCustomers(): void
    {
        DB::table('leads')->whereNotNull('email')->orderBy('id')->chunkById(200, function ($leads) {
            foreach ($leads as $lead) {
                $email = mb_strtolower(trim($lead->email));
                $customerId = DB::table('customers')->where('email', $email)->value('id')
                    ?? DB::table('customers')->insertGetId([
                        'name' => $lead->customer_name,
                        'company_name' => $lead->company_name,
                        'email' => $email,
                        'phone' => $lead->phone,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                DB::table('leads')->where('id', $lead->id)->update(['customer_id' => $customerId, 'submitted_at' => $lead->created_at]);
            }
        });
    }
};
