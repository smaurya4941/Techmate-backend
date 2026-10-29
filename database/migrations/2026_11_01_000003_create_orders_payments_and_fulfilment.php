<?php

use App\Support\SchemaChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 orders. An order copies the accepted quotation's commercial terms
 * (it never mirrors the mutable lead). Sales closure, payment and fulfilment are
 * independent: orders.status tracks fulfilment, payments is its own ledger, and
 * the amount outstanding is always derived from the ledger, never stored.
 */
return new class extends Migration
{
    public const ORDER_STATUSES = ['pending_confirmation', 'confirmed', 'processing', 'ready_for_dispatch', 'dispatched', 'delivered', 'completed', 'cancelled'];

    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 32)->unique();
            // One order per won lead and per accepted quotation: the idempotency backstop.
            $table->foreignId('lead_id')->unique()->constrained('leads')->restrictOnDelete();
            $table->foreignId('quotation_id')->unique()->constrained('quotations')->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('status', 25)->default('pending_confirmation');
            $table->char('currency_code', 3);
            $table->string('customer_name_snapshot', 150);
            $table->string('company_name_snapshot', 200)->nullable();
            $table->string('customer_email_snapshot', 255)->nullable();
            $table->string('customer_phone_snapshot', 30)->nullable();
            $table->text('billing_address_snapshot')->nullable();
            $table->text('delivery_address')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('freight_amount', 14, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);
            $table->text('payment_terms')->nullable();
            $table->text('delivery_terms')->nullable();
            $table->boolean('installation_required')->default(false);
            $table->timestamp('installation_completed_at')->nullable();
            $table->timestamp('ordered_at')->useCurrent();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'ordered_at']);
        });

        SchemaChecks::in('orders', 'status', self::ORDER_STATUSES);
        SchemaChecks::add('orders', 'grand_total_reconciles', '`grand_total` = `subtotal` - `discount_total` + `tax_total` + `freight_amount`');

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->string('product_name_snapshot', 200);
            $table->string('product_code_snapshot', 64)->nullable();
            $table->text('description_snapshot')->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('line_subtotal', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 30);
            $table->string('from_status', 25)->nullable();
            $table->string('to_status', 25)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('occurred_at')->useCurrent();

            $table->index(['order_id', 'occurred_at']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('paid_at');
            $table->string('method', 20);
            // Receipt / transaction reference. Unique per order+method so a repeated entry is rejected.
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            // recorded -> verified (second person) ; voided keeps the row for the audit trail.
            $table->string('status', 15)->default('recorded');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'method', 'reference']);
            $table->index(['order_id', 'status']);
        });

        SchemaChecks::in('payments', 'status', ['recorded', 'verified', 'voided']);
        SchemaChecks::in('payments', 'method', ['bank_transfer', 'cheque', 'cash', 'upi', 'card', 'other']);
        SchemaChecks::add('payments', 'amount_positive', '`amount` > 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
