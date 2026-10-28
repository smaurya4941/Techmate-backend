<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Leads\Pages\ListLeads;
use App\Filament\Resources\Leads\Pages\ViewLead;
use App\Filament\Resources\Leads\RelationManagers\ActivitiesRelationManager;
use App\Filament\Resources\Leads\RelationManagers\FollowUpsRelationManager;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;
use App\Services\LeadWorkflow;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

class LeadManagementTest extends AdminTestCase
{
    private User $exec;

    private User $otherExec;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->exec = $this->staff(User::ROLE_SALES_EXECUTIVE);
        $this->otherExec = $this->staff(User::ROLE_SALES_EXECUTIVE);
        $this->manager = $this->staff(User::ROLE_SALES_MANAGER);
    }

    private function workflow(): LeadWorkflow
    {
        return app(LeadWorkflow::class);
    }

    public function test_a_sales_executive_sees_only_their_own_leads(): void
    {
        $mine = Lead::factory()->create(['assigned_to' => $this->exec->id]);
        $theirs = Lead::factory()->create(['assigned_to' => $this->otherExec->id]);
        $unassigned = Lead::factory()->create();

        Livewire::actingAs($this->exec)->test(ListLeads::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs, $unassigned]);
    }

    public function test_direct_urls_to_other_peoples_leads_are_not_found(): void
    {
        $theirs = Lead::factory()->create(['assigned_to' => $this->otherExec->id]);
        $unassigned = Lead::factory()->create();
        $mine = Lead::factory()->create(['assigned_to' => $this->exec->id]);

        $this->actingAs($this->exec)->get("/admin/leads/{$mine->id}")->assertOk();
        $this->actingAs($this->exec)->get("/admin/leads/{$theirs->id}")->assertNotFound();
        $this->actingAs($this->exec)->get("/admin/leads/{$unassigned->id}")->assertNotFound();
    }

    public function test_crafted_livewire_requests_cannot_reach_other_leads(): void
    {
        $theirs = Lead::factory()->create(['assigned_to' => $this->otherExec->id]);

        $this->expectException(ModelNotFoundException::class);
        Livewire::actingAs($this->exec)->test(ViewLead::class, ['record' => $theirs->getKey()]);
    }

    public function test_managers_and_super_admins_see_every_lead(): void
    {
        $leads = [
            Lead::factory()->create(['assigned_to' => $this->exec->id]),
            Lead::factory()->create(['assigned_to' => $this->otherExec->id]),
            Lead::factory()->create(),
        ];

        foreach ([$this->manager, $this->superAdmin()] as $user) {
            Livewire::actingAs($user)->test(ListLeads::class)->assertCanSeeTableRecords($leads);
        }
    }

    public function test_catalogue_managers_have_no_access_to_leads(): void
    {
        $lead = Lead::factory()->create();

        $this->actingAs($this->catalogueManager())->get('/admin/leads')->assertForbidden();
        // Out-of-scope records resolve to 404 so their existence is not revealed.
        $this->actingAs($this->catalogueManager())->get("/admin/leads/{$lead->id}")->assertNotFound();
    }

    public function test_list_filters_by_status_assignee_and_overdue_follow_up(): void
    {
        $new = Lead::factory()->create(['assigned_to' => $this->exec->id]);
        $contacted = Lead::factory()->status('contacted')->create(['assigned_to' => $this->otherExec->id]);
        FollowUp::factory()->create(['lead_id' => $contacted->id, 'due_at' => now()->subDay(), 'assigned_to' => $this->otherExec->id]);

        Livewire::actingAs($this->manager)->test(ListLeads::class)
            ->filterTable('status', ['contacted'])
            ->assertCanSeeTableRecords([$contacted])->assertCanNotSeeTableRecords([$new])
            ->removeTableFilters()
            ->filterTable('assigned_to', $this->exec->id)
            ->assertCanSeeTableRecords([$new])->assertCanNotSeeTableRecords([$contacted])
            ->removeTableFilters()
            ->filterTable('follow_up', 'overdue')
            ->assertCanSeeTableRecords([$contacted])->assertCanNotSeeTableRecords([$new]);
    }

    public function test_search_finds_leads_by_reference_and_customer(): void
    {
        $lead = Lead::factory()->create(['customer_name' => 'Anita Rao', 'company_name' => 'Spice Route Hotels', 'assigned_to' => $this->exec->id]);
        $other = Lead::factory()->create(['customer_name' => 'Zed Other', 'assigned_to' => $this->exec->id]);

        Livewire::actingAs($this->exec)->test(ListLeads::class)
            ->searchTable('Spice Route')->assertCanSeeTableRecords([$lead])->assertCanNotSeeTableRecords([$other])
            ->searchTable($lead->public_reference)->assertCanSeeTableRecords([$lead]);
    }

    public function test_manager_assigns_and_reassigns_with_an_audit_trail(): void
    {
        $lead = Lead::factory()->create();

        $this->workflow()->assign($lead, $this->exec->id, $this->manager, 'Hotel enquiry');
        $this->assertSame($this->exec->id, $lead->fresh()->assigned_to);

        $this->workflow()->assign($lead, $this->otherExec->id, $this->manager);
        $this->assertSame($this->otherExec->id, $lead->fresh()->assigned_to);

        $activities = $lead->activities()->where('activity_type', 'assignment')->get();
        $this->assertCount(2, $activities);
        $this->assertSame($this->exec->id, $activities[1]->metadata['from_user_id']);
        $this->assertSame($this->otherExec->id, $activities[1]->metadata['to_user_id']);
        $this->assertSame($this->manager->id, $activities[1]->user_id);
    }

    public function test_a_sales_executive_cannot_assign_or_reassign(): void
    {
        $lead = Lead::factory()->create(['assigned_to' => $this->exec->id]);

        $this->expectException(AuthorizationException::class);
        $this->workflow()->assign($lead, $this->otherExec->id, $this->exec);
    }

    public function test_assignment_to_inactive_or_non_sales_users_is_refused(): void
    {
        $lead = Lead::factory()->create();

        foreach ([$this->staff(User::ROLE_SALES_EXECUTIVE, ['is_active' => false]), $this->catalogueManager()] as $invalid) {
            try {
                $this->workflow()->assign($lead, $invalid->id, $this->manager);
                $this->fail('Assignment should have been refused.');
            } catch (ValidationException) {
                $this->assertNull($lead->fresh()->assigned_to);
            }
        }
    }

    public function test_the_assign_action_is_hidden_from_executives_and_works_for_managers(): void
    {
        $lead = Lead::factory()->create(['assigned_to' => $this->exec->id]);

        Livewire::actingAs($this->exec)->test(ViewLead::class, ['record' => $lead->getKey()])
            ->assertActionHidden('assign');

        Livewire::actingAs($this->manager)->test(ViewLead::class, ['record' => $lead->getKey()])
            ->callAction('assign', ['assigned_to' => $this->otherExec->id]);

        $this->assertSame($this->otherExec->id, $lead->fresh()->assigned_to);
    }

    public function test_status_changes_are_validated_and_recorded_with_old_and_new_values(): void
    {
        $lead = Lead::factory()->create(['assigned_to' => $this->exec->id]);

        $this->workflow()->changeStatus($lead, 'contacted', $this->exec, 'Spoke to buyer');
        $this->workflow()->changeStatus($lead, 'qualified', $this->exec);

        $lead->refresh();
        $this->assertSame('qualified', $lead->status);
        $this->assertNotNull($lead->last_contacted_at);

        $changes = $lead->activities()->where('activity_type', 'status_change')->get();
        $this->assertSame(['new', 'contacted'], [$changes[0]->old_status, $changes[0]->new_status]);
        $this->assertSame(['contacted', 'qualified'], [$changes[1]->old_status, $changes[1]->new_status]);
        $this->assertSame('Spoke to buyer', $changes[0]->description);
    }

    public function test_invalid_status_transitions_are_refused_and_change_nothing(): void
    {
        $lead = Lead::factory()->create(['assigned_to' => $this->exec->id]);

        try {
            $this->workflow()->changeStatus($lead, 'won', $this->exec);
            $this->fail('new -> won must be refused');
        } catch (ValidationException) {
        }

        $this->assertSame('new', $lead->fresh()->status);
        $this->assertSame(0, $lead->activities()->count());
    }

    public function test_closing_a_lead_stamps_closed_at(): void
    {
        $lead = Lead::factory()->status('proposal')->create(['assigned_to' => $this->exec->id]);

        $this->workflow()->changeStatus($lead, 'won', $this->exec);

        $this->assertNotNull($lead->fresh()->closed_at);
    }

    public function test_status_cannot_be_changed_on_someone_elses_lead(): void
    {
        $lead = Lead::factory()->create(['assigned_to' => $this->otherExec->id]);

        try {
            $this->workflow()->changeStatus($lead, 'contacted', $this->exec);
            $this->fail('Expected an authorisation failure.');
        } catch (AuthorizationException) {
        }

        $this->assertSame('new', $lead->fresh()->status);
        $this->assertSame(0, LeadActivity::count());
    }

    public function test_calls_notes_and_meetings_are_logged_on_the_timeline(): void
    {
        $lead = Lead::factory()->create(['assigned_to' => $this->exec->id]);

        $this->workflow()->logActivity($lead, 'note', 'Customer prefers gas ovens.', $this->exec);
        $this->workflow()->logActivity($lead, 'call', 'Discussed delivery timelines.', $this->exec, now()->subHour());

        $this->assertSame(['call', 'note'], $lead->activities()->pluck('activity_type')->sort()->values()->all());
        $this->assertNotNull($lead->fresh()->last_contacted_at);

        Livewire::actingAs($this->exec)->test(ActivitiesRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
            ->assertCanSeeTableRecords($lead->activities)
            ->assertSeeText('Discussed delivery timelines.');
    }

    public function test_system_activity_types_and_future_dates_cannot_be_logged_manually(): void
    {
        $lead = Lead::factory()->create(['assigned_to' => $this->exec->id]);

        foreach ([['status_change', now()], ['note', now()->addDay()]] as [$type, $when]) {
            try {
                $this->workflow()->logActivity($lead, $type, 'x', $this->exec, $when);
                $this->fail('Expected refusal.');
            } catch (ValidationException) {
            }
        }
        $this->assertSame(0, $lead->activities()->count());
    }

    public function test_lead_history_is_append_only_even_for_super_admins(): void
    {
        $lead = Lead::factory()->create(['assigned_to' => $this->exec->id]);
        $activity = $this->workflow()->logActivity($lead, 'note', 'original', $this->exec);

        $this->assertFalse($this->superAdmin()->can('update', $activity));
        $this->assertFalse($this->superAdmin()->can('delete', $activity));

        $this->expectException(\LogicException::class);
        $activity->update(['description' => 'tampered']);
    }

    public function test_follow_ups_are_scheduled_completed_and_cancelled(): void
    {
        $lead = Lead::factory()->create(['assigned_to' => $this->exec->id]);

        $first = $this->workflow()->scheduleFollowUp($lead, ['title' => 'Send brochure', 'due_at' => now()->addDay()], $this->exec);
        $second = $this->workflow()->scheduleFollowUp($lead, ['title' => 'Check budget', 'due_at' => now()->addDays(2), 'assigned_to' => $this->exec->id], $this->exec);

        $this->assertSame($this->exec->id, $first->assigned_to);
        $this->assertSame('pending', $first->status);

        $this->workflow()->completeFollowUp($first, $this->exec);
        $this->workflow()->cancelFollowUp($second, $this->exec);

        $this->assertSame('completed', $first->fresh()->status);
        $this->assertNotNull($first->fresh()->completed_at);
        $this->assertSame('cancelled', $second->fresh()->status);
        $this->assertNull($second->fresh()->completed_at);
        $this->assertSame(2, $lead->activities()->where('activity_type', 'note')->count());

        try {
            $this->workflow()->completeFollowUp($first, $this->exec);
            $this->fail('A closed follow-up cannot be completed again.');
        } catch (ValidationException) {
        }
    }

    public function test_overdue_follow_ups_are_derived_in_the_ui(): void
    {
        $lead = Lead::factory()->create(['assigned_to' => $this->exec->id]);
        $overdue = FollowUp::factory()->create(['lead_id' => $lead->id, 'due_at' => now()->subDay(), 'assigned_to' => $this->exec->id]);
        $done = FollowUp::factory()->create(['lead_id' => $lead->id, 'due_at' => now()->subDay(), 'status' => 'completed']);

        $this->assertTrue($overdue->isOverdue());
        $this->assertFalse($done->isOverdue());

        Livewire::actingAs($this->exec)->test(FollowUpsRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
            ->assertCanSeeTableRecords([$overdue, $done])
            ->assertSeeText('Overdue');
    }

    public function test_follow_ups_cannot_be_handed_to_arbitrary_users_by_executives(): void
    {
        $lead = Lead::factory()->create(['assigned_to' => $this->exec->id]);

        $this->expectException(ValidationException::class);
        $this->workflow()->scheduleFollowUp($lead, ['title' => 'x', 'due_at' => now()->addDay(), 'assigned_to' => $this->otherExec->id], $this->exec);
    }

    public function test_follow_ups_on_other_leads_cannot_be_completed(): void
    {
        $followUp = FollowUp::factory()->create([
            'lead_id' => Lead::factory()->create(['assigned_to' => $this->otherExec->id])->id,
        ]);

        $this->expectException(AuthorizationException::class);
        $this->workflow()->completeFollowUp($followUp, $this->exec);
    }

    public function test_follow_up_complete_action_works_from_the_relation_manager(): void
    {
        $lead = Lead::factory()->create(['assigned_to' => $this->exec->id]);
        $followUp = FollowUp::factory()->create(['lead_id' => $lead->id, 'assigned_to' => $this->exec->id]);

        Livewire::actingAs($this->exec)->test(FollowUpsRelationManager::class, ['ownerRecord' => $lead, 'pageClass' => ViewLead::class])
            ->callAction(TestAction::make('complete')->table($followUp));

        $this->assertSame('completed', $followUp->fresh()->status);
    }

    public function test_leads_and_followups_cannot_be_deleted_by_anyone(): void
    {
        $lead = Lead::factory()->create(['assigned_to' => $this->exec->id]);
        $followUp = FollowUp::factory()->create(['lead_id' => $lead->id]);

        foreach ([$this->superAdmin(), $this->manager, $this->exec] as $user) {
            $this->assertFalse($user->can('delete', $lead));
            $this->assertFalse($user->can('delete', $followUp));
        }
    }

    public function test_bulk_assignment_is_limited_to_authorised_roles(): void
    {
        $leads = Lead::factory()->count(2)->create();

        Livewire::actingAs($this->exec)->test(ListLeads::class)
            ->assertTableBulkActionHidden('assign');

        Livewire::actingAs($this->manager)->test(ListLeads::class)
            ->selectTableRecords($leads->pluck('id')->all())
            ->callAction(TestAction::make('assign')->table()->bulk(), ['assigned_to' => $this->exec->id]);

        $this->assertSame(2, Lead::where('assigned_to', $this->exec->id)->count());
    }

    public function test_customer_message_is_displayed_as_escaped_text(): void
    {
        $lead = Lead::factory()->create(['assigned_to' => $this->exec->id, 'message' => '<script>alert("x")</script>Hello']);

        $html = $this->actingAs($this->exec)->get("/admin/leads/{$lead->id}")->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert("x")</script>', $html);
        $this->assertStringContainsString('Hello', $html);
    }
}
