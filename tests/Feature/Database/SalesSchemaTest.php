<?php

namespace Tests\Feature\Database;

use App\Models\Category;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadProduct;
use App\Models\Page;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Setting;
use App\Models\User;
use App\Support\QuotationMath;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DevelopmentSampleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\DatabaseTestCase;

class SalesSchemaTest extends DatabaseTestCase
{
    public function test_lead_gets_unique_non_sequential_reference_and_internal_fields_are_protected(): void
    {
        $a = Lead::create(['customer_name' => 'A', 'email' => 'a@example.test', 'status' => 'won', 'priority' => 'urgent', 'assigned_to' => 1])->fresh();
        $b = Lead::create(['customer_name' => 'B', 'phone' => '+10000000001'])->fresh();

        $this->assertMatchesRegularExpression('/^TM-[A-Z2-9]{10}$/', $a->public_reference);
        $this->assertNotSame($a->public_reference, $b->public_reference);
        $this->assertSame(['new', 'normal', null], [$a->status, $a->priority, $a->assigned_to]);
    }

    public function test_lead_public_reference_is_unique_in_the_database(): void
    {
        $lead = Lead::factory()->create();

        $this->expectException(QueryException::class);
        Lead::factory()->create(['public_reference' => $lead->public_reference]);
    }

    public function test_lead_status_transitions_are_defined(): void
    {
        $lead = Lead::factory()->status('new')->make();
        $this->assertTrue($lead->canTransitionTo('contacted'));
        $this->assertFalse($lead->canTransitionTo('won'));
        $this->assertFalse(Lead::factory()->status('won')->make()->canTransitionTo('new'));

        foreach (Lead::STATUSES as $status) {
            $this->assertArrayHasKey($status, Lead::TRANSITIONS);
        }
    }

    public function test_lead_products_keep_snapshots_when_product_is_renamed_or_removed(): void
    {
        $product = Product::factory()->create(['name' => 'Original Name', 'sku' => 'ORIG-1']);
        $item = LeadProduct::factory()->create([
            'product_id' => $product->id,
            'product_name_snapshot' => $product->name,
            'product_code_snapshot' => $product->sku,
            'quantity' => 3,
        ]);

        $product->update(['name' => 'Renamed', 'sku' => 'NEW-1']);
        $this->assertSame('Original Name', $item->fresh()->product_name_snapshot);

        $product->forceDelete();
        $item->refresh();
        $this->assertNull($item->product_id);
        $this->assertSame('ORIG-1', $item->product_code_snapshot);
    }

    public function test_soft_deleted_product_is_still_reachable_from_lead_product(): void
    {
        $product = Product::factory()->create();
        $item = LeadProduct::factory()->create(['product_id' => $product->id]);
        $product->delete();

        $this->assertTrue($item->fresh()->product->is($product));
    }

    public function test_activities_and_follow_ups_survive_staff_removal_and_activities_are_append_only(): void
    {
        $staff = User::factory()->create();
        $lead = Lead::factory()->create(['assigned_to' => $staff->id]);
        $activity = LeadActivity::factory()->create(['lead_id' => $lead->id, 'user_id' => $staff->id]);
        $follow = FollowUp::factory()->create(['lead_id' => $lead->id, 'assigned_to' => $staff->id, 'created_by' => $staff->id]);

        $this->assertCount(1, $staff->assignedLeads);
        $this->assertCount(1, $staff->assignedFollowUps);

        $staff->delete();

        $this->assertNull($lead->fresh()->assigned_to);
        $this->assertNull($activity->fresh()->user_id);
        $this->assertNull($follow->fresh()->assigned_to);

        $this->expectException(LogicException::class);
        $activity->fresh()->update(['description' => 'edited']);
    }

    public function test_activity_cannot_be_deleted_through_eloquent_and_stores_json_metadata(): void
    {
        $activity = LeadActivity::factory()->create([
            'activity_type' => 'status_change', 'old_status' => 'new', 'new_status' => 'contacted',
            'metadata' => ['channel' => 'phone'],
        ]);

        $this->assertSame(['channel' => 'phone'], $activity->fresh()->metadata);

        $this->expectException(LogicException::class);
        $activity->delete();
    }

    public function test_overdue_follow_ups_are_derived_not_stored(): void
    {
        $late = FollowUp::factory()->create(['due_at' => now()->subHour()]);
        FollowUp::factory()->create(['due_at' => now()->addHour()]);
        FollowUp::factory()->create(['due_at' => now()->subHour(), 'status' => 'completed']);

        $this->assertSame([$late->id], FollowUp::overdue()->pluck('id')->all());
        $this->assertTrue($late->isOverdue());
    }

    public function test_deleting_a_lead_cascades_working_data_but_not_when_quotations_exist(): void
    {
        $open = Lead::factory()->create();
        LeadProduct::factory()->create(['lead_id' => $open->id]);
        LeadActivity::factory()->create(['lead_id' => $open->id]);
        FollowUp::factory()->create(['lead_id' => $open->id]);
        $open->delete();
        $this->assertSame(0, LeadProduct::count() + LeadActivity::count() + FollowUp::count());

        $quoted = Lead::factory()->create();
        Quotation::factory()->create(['lead_id' => $quoted->id]);

        try {
            $quoted->delete();
            $this->fail('A lead with quotations was deleted.');
        } catch (QueryException) {
            $this->assertSame(1, Quotation::count());
        }
    }

    public function test_quotation_number_is_generated_and_unique(): void
    {
        $a = Quotation::factory()->create()->fresh();
        $b = Quotation::factory()->create()->fresh();

        $this->assertMatchesRegularExpression('/^QT-\d{6}-[A-Z2-9]{6}$/', $a->quotation_number);
        $this->assertNotSame($a->quotation_number, $b->quotation_number);

        $this->expectException(QueryException::class);
        Quotation::factory()->create(['quotation_number' => $a->quotation_number]);
    }

    public function test_a_lead_can_have_several_quotations_and_status_transitions_are_defined(): void
    {
        $lead = Lead::factory()->create();
        Quotation::factory()->count(2)->create(['lead_id' => $lead->id]);

        $this->assertCount(2, $lead->quotations);

        $draft = new Quotation(['status' => 'draft']);
        $draft->status = 'draft';
        $this->assertTrue($draft->canTransitionTo('sent'));
        $this->assertFalse($draft->canTransitionTo('accepted'));
        $draft->status = 'accepted';
        $this->assertFalse($draft->canTransitionTo('cancelled'));
    }

    public function test_quotation_snapshots_ignore_later_lead_and_product_changes(): void
    {
        $lead = Lead::factory()->create(['customer_name' => 'Before', 'email' => 'before@example.test']);
        $product = Product::factory()->priced('100.00', 'USD')->create(['name' => 'Old Name', 'sku' => 'OLD-1']);

        $quotation = Quotation::factory()->create([
            'lead_id' => $lead->id,
            'customer_name_snapshot' => $lead->customer_name,
            'customer_email_snapshot' => $lead->email,
        ]);
        QuotationItem::factory()->line(2, '100.00', '20.00', '10')->create([
            'quotation_id' => $quotation->id,
            'product_id' => $product->id,
            'product_name_snapshot' => $product->name,
            'product_code_snapshot' => $product->sku,
        ]);

        $lead->update(['customer_name' => 'After', 'email' => 'after@example.test']);
        $product->update(['name' => 'New Name', 'price' => '999.00']);
        $product->forceDelete();

        $item = $quotation->fresh()->items->first();
        $this->assertSame('Before', $quotation->fresh()->customer_name_snapshot);
        $this->assertSame('before@example.test', $quotation->fresh()->customer_email_snapshot);
        $this->assertSame('Old Name', $item->product_name_snapshot);
        $this->assertSame('OLD-1', $item->product_code_snapshot);
        $this->assertNull($item->product_id);
        $this->assertSame('100.00', $item->unit_price);
        $this->assertSame('198.00', $item->line_total);
    }

    public function test_quotation_header_totals_reconcile_with_items_via_one_calculation(): void
    {
        $quotation = Quotation::factory()->create();
        $lines = [[3, '19.99', '0', '7.5'], [2, '100.00', '50.00', '10']];
        $parts = [];

        foreach ($lines as $i => [$qty, $price, $discount, $rate]) {
            $m = QuotationMath::line($qty, $price, $discount, $rate);
            QuotationItem::factory()->line($qty, $price, $discount, $rate)->create(['quotation_id' => $quotation->id, 'sort_order' => $i]);
            $parts[] = ['gross' => $m['gross'], 'discount_amount' => $discount === '0' ? '0.00' : $discount, 'tax_amount' => $m['tax_amount']];
        }

        $quotation->forceFill(QuotationMath::totals($parts))->save();
        $quotation->refresh();

        $this->assertSame('259.97', $quotation->subtotal);
        $this->assertSame('50.00', $quotation->discount_total);
        $this->assertSame('19.50', $quotation->tax_total);
        $this->assertSame('229.47', $quotation->grand_total);
        $this->assertSame($quotation->grand_total, number_format($quotation->items->sum(fn ($i) => (float) $i->line_total), 2, '.', ''));
    }

    public function test_database_rejects_invalid_sales_values(): void
    {
        $this->requireCheckConstraints();
        $lead = Lead::factory()->create();
        $quotation = Quotation::factory()->create();
        $item = fn (array $o) => QuotationItem::factory()->create($o + ['quotation_id' => $quotation->id]);

        $bad = [
            'lead without contact' => fn () => Lead::factory()->create(['email' => null, 'phone' => null]),
            'lead bad status' => fn () => Lead::factory()->create(['status' => 'bogus']),
            'lead bad priority' => fn () => Lead::factory()->create(['priority' => 'bogus']),
            'lead product qty 0' => fn () => LeadProduct::factory()->create(['quantity' => 0]),
            'activity bad type' => fn () => LeadActivity::factory()->create(['activity_type' => 'bogus']),
            'follow-up bad status' => fn () => FollowUp::factory()->create(['status' => 'overdue']),
            'quotation bad status' => fn () => Quotation::factory()->create(['status' => 'bogus']),
            'quotation total mismatch' => fn () => Quotation::factory()->create(['subtotal' => '10.00', 'grand_total' => '99.00']),
            'quotation negative' => fn () => Quotation::factory()->create(['subtotal' => '-5.00', 'grand_total' => '-5.00']),
            'quotation expires before issue' => fn () => Quotation::factory()->create(['issue_date' => '2026-02-01', 'valid_until' => '2026-01-01']),
            'quotation no contact snapshot' => fn () => Quotation::factory()->create(['customer_email_snapshot' => null, 'customer_phone_snapshot' => null]),
            'item qty 0' => fn () => $item(['quantity' => 0]),
            'item negative price' => fn () => $item(['unit_price' => '-1.00']),
            'item tax rate over 100' => fn () => $item(['tax_rate' => '101.00']),
            'item subtotal mismatch' => fn () => $item(['line_subtotal' => '1.00']),
            'item total mismatch' => fn () => $item(['line_total' => '1.00']),
        ];

        foreach ($bad as $label => $attempt) {
            try {
                $attempt();
                $this->fail("'{$label}' was accepted by the database.");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertNotNull($lead->id);
    }

    public function test_monetary_columns_do_not_use_floating_point(): void
    {
        $this->requireCheckConstraints(); // SQLite reports DECIMAL as "numeric"; assert on MySQL/MariaDB only.
        $columns = collect(Schema::getColumns('quotation_items'))->keyBy('name');

        foreach (['unit_price', 'discount_amount', 'tax_amount', 'line_subtotal', 'line_total'] as $name) {
            $this->assertStringContainsString('decimal', strtolower($columns[$name]['type_name']), $name);
        }
    }

    public function test_pages_and_settings_are_unique_and_private_by_default(): void
    {
        $page = Page::factory()->create();
        $setting = Setting::factory()->create(['value' => '42', 'value_type' => 'number']);

        $this->assertSame('draft', $page->fresh()->status);
        $this->assertFalse($setting->fresh()->is_public);
        $this->assertSame(42, $setting->fresh()->typedValue());
        $this->assertSame(0, Page::published()->count());

        Page::factory()->published()->create();
        $this->assertSame(1, Page::published()->count());

        try {
            Page::factory()->create(['slug' => $page->slug]);
            $this->fail('Duplicate page slug accepted.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(QueryException::class);
        Setting::factory()->create(['key' => $setting->key]);
    }

    public function test_seeders_are_repeatable_and_keep_foreign_keys_valid(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DevelopmentSampleSeeder::class);

        $this->assertSame(1, Product::count());
        $this->assertSame(1, Lead::count());
        $this->assertSame(2, Category::count());
        $this->assertSame(Product::first()->sku, LeadProduct::first()->product_code_snapshot);
    }
}
