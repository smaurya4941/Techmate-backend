<?php

namespace Tests\Feature\Sales;

use App\Filament\Resources\FollowUps\Pages\ListFollowUps;
use App\Models\AuditEntry;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\NotificationOutbox;
use App\Models\User;
use App\Services\FollowUpReminders;
use App\Services\LeadOutcomeService;
use App\Services\LeadWorkflow;
use App\Services\OrderService;
use App\Services\SalesReports;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

class SalesOperationsTest extends SalesTestCase
{
    private function workflow(): LeadWorkflow
    {
        return app(LeadWorkflow::class);
    }

    // ---- Assignment --------------------------------------------------------

    public function test_assigning_a_new_lead_moves_it_to_assigned_notifies_the_owner_and_records_history(): void
    {
        $lead = Lead::factory()->create();

        $this->workflow()->assign($lead, $this->exec->id, $this->manager, 'Bakery specialist');

        $lead->refresh();
        $this->assertSame(['assigned', $this->exec->id], [$lead->status, $lead->assigned_to]);

        $mail = NotificationOutbox::where('type', 'lead.assigned')->firstOrFail();
        $this->assertSame('exec@example.test', $mail->recipient_email);

        $assignment = $lead->activities()->where('activity_type', 'assignment')->firstOrFail();
        $this->assertSame($this->manager->id, $assignment->user_id);
        $this->assertSame([null, $this->exec->id], [$assignment->metadata['from_user_id'], $assignment->metadata['to_user_id']]);
        $this->assertTrue(AuditEntry::where('event', 'lead.assigned')->where('subject_id', $lead->id)->exists());
    }

    public function test_reassignment_preserves_the_previous_owner_and_keeps_the_reference_and_status(): void
    {
        $lead = $this->lead($this->exec, 'qualified');
        $reference = $lead->public_reference;

        $this->workflow()->assign($lead, $this->otherExec->id, $this->manager);
        $this->workflow()->assign($lead->fresh(), $this->exec->id, $this->manager);

        $history = $lead->activities()->where('activity_type', 'assignment')->get();
        $this->assertSame([[$this->exec->id, $this->otherExec->id], [$this->otherExec->id, $this->exec->id]],
            $history->map(fn ($a) => [$a->metadata['from_user_id'], $a->metadata['to_user_id']])->all());
        $this->assertSame($reference, $lead->fresh()->public_reference);
        $this->assertSame('qualified', $lead->fresh()->status);
    }

    public function test_assignment_rules(): void
    {
        $lead = $this->lead($this->exec);

        try {
            $this->workflow()->assign($lead, $this->otherExec->id, $this->exec);
            $this->fail('Executives cannot assign.');
        } catch (AuthorizationException) {
        }

        foreach ([$this->ops->id, User::factory()->inactive()->create(['role' => 'sales_executive'])->id, 999999] as $invalid) {
            try {
                $this->workflow()->assign($lead, $invalid, $this->manager);
                $this->fail('Only active sales staff can own leads.');
            } catch (ValidationException) {
            }
        }

        $closed = $this->lead($this->exec, 'lost');
        $this->expectException(ValidationException::class);
        $this->workflow()->assign($closed, $this->otherExec->id, $this->manager);
    }

    // ---- Activities and response time ---------------------------------------

    public function test_first_response_is_the_earliest_contact_and_notes_do_not_count(): void
    {
        $lead = $this->lead($this->exec, 'assigned');
        $this->workflow()->logActivity($lead, 'note', 'Reviewed requirements', $this->exec);
        $this->assertNull($lead->fresh()->first_response_at);

        $this->workflow()->logActivity($lead, 'email', 'Sent brochure', $this->exec, now()->subMinutes(10));
        $this->workflow()->logActivity($lead, 'call', 'Spoke to the buyer', $this->exec, now()->subMinutes(40));

        $lead->refresh();
        $this->assertEquals(now()->subMinutes(40)->startOfSecond(), $lead->first_response_at->startOfSecond());
        $this->assertEquals(now()->subMinutes(10)->startOfSecond(), $lead->last_contacted_at->startOfSecond());
        $this->assertSame('contacted', $lead->status);
    }

    public function test_internal_notes_never_overwrite_the_original_enquiry(): void
    {
        $lead = Lead::factory()->create(['message' => 'Original customer words', 'assigned_to' => $this->exec->id]);

        $this->workflow()->logActivity($lead, 'note', 'Internal: budget is tight', $this->exec);

        $this->assertSame('Original customer words', $lead->fresh()->message);
        $this->assertSame('Internal: budget is tight', $lead->activities()->where('activity_type', 'note')->latest('id')->first()->description);
    }

    public function test_priority_changes_are_recorded_and_validated(): void
    {
        $lead = $this->lead();

        $this->workflow()->changePriority($lead, 'urgent', $this->exec);
        $this->assertSame('urgent', $lead->fresh()->priority);
        $this->assertTrue($lead->activities()->where('description', 'like', '%Priority changed from Normal to Urgent%')->exists());

        $this->expectException(ValidationException::class);
        $this->workflow()->changePriority($lead, 'asap', $this->exec);
    }

    // ---- Follow-ups and reminders --------------------------------------------

    public function test_a_reminder_is_sent_once_before_due_and_an_overdue_notice_once_after(): void
    {
        $lead = $this->lead();
        $task = $this->workflow()->scheduleFollowUp($lead, ['title' => 'Call about quote', 'due_at' => now()->addHour(), 'remind_before_minutes' => 30], $this->exec);
        $reminders = app(FollowUpReminders::class);

        $this->assertSame(['reminders' => 0, 'overdue' => 0], $reminders->dispatchDue(), 'Too early for the heads-up.');

        $this->travel(40)->minutes();
        $this->assertSame(['reminders' => 1, 'overdue' => 0], $reminders->dispatchDue());
        $this->assertSame(['reminders' => 0, 'overdue' => 0], $reminders->dispatchDue(), 'Running the scheduler again sends nothing new.');

        $this->travel(30)->minutes();
        $this->assertSame(['reminders' => 0, 'overdue' => 1], $reminders->dispatchDue());
        $this->assertSame(['reminders' => 0, 'overdue' => 0], $reminders->dispatchDue());

        $this->assertSame(['followup.reminder', 'followup.overdue'], NotificationOutbox::orderBy('id')->pluck('type')->all());
        $this->assertSame('exec@example.test', NotificationOutbox::first()->recipient_email);
        $this->assertNotNull($task->fresh()->reminder_sent_at);
    }

    public function test_completed_cancelled_and_orphaned_tasks_are_never_notified(): void
    {
        $lead = $this->lead();
        $done = $this->workflow()->scheduleFollowUp($lead, ['title' => 'Done', 'due_at' => now()->addMinutes(10), 'remind_before_minutes' => 30], $this->exec);
        $cancelled = $this->workflow()->scheduleFollowUp($lead, ['title' => 'Cancelled', 'due_at' => now()->addMinutes(10)], $this->exec);
        $this->workflow()->completeFollowUp($done, $this->exec, 'Spoke already');
        $this->workflow()->cancelFollowUp($cancelled, $this->exec);

        $this->travel(2)->hours();

        $this->assertSame(['reminders' => 0, 'overdue' => 0], app(FollowUpReminders::class)->dispatchDue());
        $this->assertSame(0, NotificationOutbox::count());
    }

    public function test_rescheduling_rearms_the_reminders(): void
    {
        $task = $this->workflow()->scheduleFollowUp($this->lead(), ['title' => 'Chase', 'due_at' => now()->addMinutes(20), 'remind_before_minutes' => 15], $this->exec);
        $reminders = app(FollowUpReminders::class);

        $this->travel(10)->minutes();
        $this->assertSame(1, $reminders->dispatchDue()['reminders']);

        $this->workflow()->rescheduleFollowUp($task->fresh(), now()->addDay(), $this->exec);
        $this->assertNull($task->fresh()->reminder_sent_at);

        $this->travel(1430)->minutes();
        $this->assertSame(1, $reminders->dispatchDue()['reminders']);
        $this->assertSame(2, NotificationOutbox::where('type', 'followup.reminder')->count());
    }

    public function test_the_scheduler_command_and_registration_exist(): void
    {
        $this->artisan('techmate:follow-up-reminders')->assertSuccessful();

        $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command);
        foreach (['techmate:outbox-dispatch', 'techmate:follow-up-reminders', 'techmate:expire-quotations'] as $command) {
            $this->assertTrue($events->contains(fn ($c) => str_contains((string) $c, $command)), "{$command} is not scheduled.");
        }
    }

    public function test_work_queue_views_derive_from_stored_dates_and_status(): void
    {
        $lead = $this->lead();
        $make = fn (string $title, $due, array $extra = []) => FollowUp::factory()->create(['lead_id' => $lead->id, 'assigned_to' => $this->exec->id, 'title' => $title, 'due_at' => $due, ...$extra]);

        $this->travelTo(now()->setTime(12, 0));
        $overdue = $make('overdue', now()->subDay());
        $today = $make('today', now()->setTime(15, 0));
        $upcoming = $make('upcoming', now()->addDays(3));
        $recent = $make('recent', now()->subDay(), ['status' => 'completed', 'completed_at' => now()->subHours(3)]);
        $old = $make('old', now()->subMonths(2), ['status' => 'completed', 'completed_at' => now()->subMonths(2)]);
        $inProgress = $make('in progress', now()->subHour(), ['status' => 'in_progress']);

        $this->assertEqualsCanonicalizing([$overdue->id, $inProgress->id], FollowUp::overdue()->pluck('id')->all());
        $this->assertSame([$today->id], FollowUp::dueToday()->pluck('id')->all());
        $this->assertSame([$upcoming->id], FollowUp::upcoming()->pluck('id')->all());
        $this->assertSame([$recent->id], FollowUp::recentlyCompleted()->pluck('id')->all());
        $this->assertFalse($old->isOverdue());
    }

    public function test_the_follow_up_queue_is_scoped_to_what_each_role_may_see(): void
    {
        $mine = FollowUp::factory()->create(['lead_id' => $this->lead($this->exec)->id, 'assigned_to' => $this->exec->id, 'due_at' => now()->subHour()]);
        $theirs = FollowUp::factory()->create(['lead_id' => $this->lead($this->otherExec)->id, 'assigned_to' => $this->otherExec->id, 'due_at' => now()->subHour()]);

        Livewire::actingAs($this->exec)->test(ListFollowUps::class)
            ->set('activeTab', 'overdue')
            ->assertCanSeeTableRecords([$mine])->assertCanNotSeeTableRecords([$theirs]);

        Livewire::actingAs($this->manager)->test(ListFollowUps::class)
            ->set('activeTab', 'overdue')
            ->assertCanSeeTableRecords([$mine, $theirs]);

        $this->actingAs($this->ops)->get('/admin/follow-ups')->assertForbidden();
    }

    // ---- Reports -------------------------------------------------------------

    /** Builds a known data set and returns the date window. */
    private function seedReportData(): array
    {
        $this->travelTo(now()->setDate(2026, 11, 15)->setTime(12, 0));

        // 5 leads in the window: 2 from the website, 1 product page, 1 spam, 1 unassigned.
        $a = $this->lead($this->exec, 'qualified', ['source' => 'contact_page']);
        $b = $this->lead($this->exec, 'qualified', ['source' => 'contact_page']);
        $c = $this->lead($this->exec, 'qualified', ['source' => 'product_page']);
        $spam = $this->lead($this->otherExec, 'spam', ['source' => 'contact_page']);
        $d = Lead::factory()->create(['source' => 'home_page']);

        // First responses: a = 30 min, b = 90 min (c, d unresponded).
        Lead::whereKey($a->id)->update(['submitted_at' => now()->subHours(5), 'first_response_at' => now()->subHours(5)->addMinutes(30)]);
        Lead::whereKey($b->id)->update(['submitted_at' => now()->subHours(5), 'first_response_at' => now()->subHours(5)->addMinutes(90)]);

        // a is won (order 275,100.56) ; b is lost for price.
        $quotation = $this->acceptedQuotation($a->fresh());
        app(LeadOutcomeService::class)->markWon($a->fresh(), $quotation->fresh(), $this->manager);
        app(LeadOutcomeService::class)->markLost($b->fresh(), 'price_budget', null, null, $this->exec);

        // c has a quotation sent (not accepted).
        $this->sentQuotation($c->fresh());

        // Payment of 100,000 on a's order.
        $order = $a->fresh()->order;
        app(OrderService::class)->recordPayment($order, ['amount' => '100000', 'paid_at' => now()->toDateString(), 'method' => 'bank_transfer', 'reference' => 'R1'], $this->ops);

        return ['2026-11-01', '2026-11-30'];
    }

    public function test_team_reports_match_independently_computed_figures(): void
    {
        [$from, $to] = $this->seedReportData();

        $r = (new SalesReports($this->manager, $from, $to))->build();

        $this->assertSame(['total' => 5, 'spam' => 1, 'genuine' => 4], $r['leads']);
        $this->assertEqualsCanonicalizing(
            [['label' => 'contact_page', 'count' => 3], ['label' => 'product_page', 'count' => 1], ['label' => 'home_page', 'count' => 1]],
            $r['by_source']
        );

        // Median of [30, 90] by the lower-middle rule = 30; average = 60.
        $this->assertSame(['responded' => 2, 'unresponded' => 2, 'average_minutes' => 60, 'median_minutes' => 30], $r['response']);

        $this->assertSame(['won' => 1, 'lost' => 1, 'win_rate_percent' => 50.0], [
            'won' => $r['win_loss']['won'], 'lost' => $r['win_loss']['lost'], 'win_rate_percent' => $r['win_loss']['win_rate_percent'],
        ]);
        $this->assertSame([['label' => 'Price or budget', 'count' => 1]], $r['win_loss']['lost_reasons']);
        $this->assertSame(['eligible' => 4, 'won' => 1, 'percent' => 25.0], $r['conversion']);

        // Two quotation versions were sent (a's accepted one and c's); one accepted.
        $this->assertSame(2, $r['quotations']['sent']);
        $this->assertSame(1, $r['quotations']['accepted']);
        $this->assertSame(50.0, $r['quotations']['conversion_percent']);
        $this->assertSame(['INR' => '550201.12'], $r['quotations']['sent_value']);

        $this->assertSame(['count' => 1, 'value' => ['INR' => '275100.56'], 'cancelled' => 0], $r['orders']);
        $this->assertSame(['INR' => '175100.56'], $r['outstanding']['totals']);
        $this->assertSame('175100.56', $r['outstanding']['orders'][0]['balance']);

        $byOwner = collect($r['by_executive'])->keyBy('name');
        $this->assertSame(['total' => 3, 'won' => 1, 'lost' => 1], collect($byOwner[$this->exec->name])->only(['total', 'won', 'lost'])->all());
        $this->assertSame(1, $byOwner['Unassigned']['total']);
    }

    public function test_reports_are_scoped_and_the_date_window_is_respected(): void
    {
        [$from, $to] = $this->seedReportData();

        $own = (new SalesReports($this->exec, $from, $to))->build();
        $this->assertSame(3, $own['leads']['total'], 'An executive sees only their own leads.');
        $this->assertSame(1, $own['orders']['count']);

        $other = (new SalesReports($this->otherExec, $from, $to))->build();
        $this->assertSame(1, $other['leads']['total']);
        $this->assertSame(0, $other['orders']['count']);
        $this->assertSame([], $other['outstanding']['orders']);

        $empty = (new SalesReports($this->manager, '2026-01-01', '2026-01-31'))->build();
        $this->assertSame(0, $empty['leads']['total']);
        $this->assertNull($empty['win_loss']['win_rate_percent']);
        $this->assertNull($empty['response']['median_minutes']);
    }

    public function test_the_reports_page_is_available_only_to_roles_with_report_access(): void
    {
        $this->seedReportData();

        foreach ([$this->exec, $this->manager, $this->admin] as $user) {
            $this->actingAs($user)->get('/admin/sales-reports')->assertOk()->assertSee('Sales reports');
        }
        $this->actingAs($this->ops)->get('/admin/sales-reports')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => User::ROLE_CATALOGUE_MANAGER]))->get('/admin/sales-reports')->assertForbidden();
    }
}
