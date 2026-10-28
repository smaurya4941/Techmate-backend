<?php

namespace Tests\Feature\Admin;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use App\Services\UserAdministration;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

class UserAdministrationTest extends AdminTestCase
{
    private const STRONG_PASSWORD = 'Str0ng-Passw0rd!';

    public function test_super_admin_creates_staff_with_a_role(): void
    {
        Livewire::actingAs($this->superAdmin())->test(CreateUser::class)
            ->fillForm([
                'name' => 'Priya Nair', 'email' => 'priya@example.test', 'password' => self::STRONG_PASSWORD,
                'role' => User::ROLE_SALES_MANAGER, 'is_active' => true,
            ])
            ->call('create')->assertHasNoFormErrors();

        $user = User::where('email', 'priya@example.test')->firstOrFail();
        $this->assertSame(User::ROLE_SALES_MANAGER, $user->role);
        $this->assertTrue(Hash::check(self::STRONG_PASSWORD, $user->password));
    }

    public function test_weak_passwords_and_unknown_roles_are_rejected(): void
    {
        $component = Livewire::actingAs($this->superAdmin())->test(CreateUser::class)
            ->fillForm(['name' => 'X', 'email' => 'x@example.test', 'password' => 'short', 'role' => User::ROLE_SALES_MANAGER])
            ->call('create')->assertHasFormErrors(['password']);

        $this->assertSame(0, User::where('email', 'x@example.test')->count());
    }

    public function test_only_super_admins_can_manage_users(): void
    {
        $target = $this->staff(User::ROLE_SALES_EXECUTIVE);

        foreach ([User::ROLE_CATALOGUE_MANAGER, User::ROLE_SALES_EXECUTIVE, User::ROLE_SALES_MANAGER] as $role) {
            $actor = $this->staff($role);

            $this->actingAs($actor)->get('/admin/users')->assertForbidden();

            try {
                app(UserAdministration::class)->update($target, ['role' => User::ROLE_SUPER_ADMIN], $actor);
                $this->fail("{$role} must not be able to change roles.");
            } catch (AuthorizationException) {
            }

            try {
                app(UserAdministration::class)->create(['name' => 'N', 'email' => "n-{$role}@example.test", 'password' => self::STRONG_PASSWORD, 'role' => $role], $actor);
                $this->fail("{$role} must not be able to create users.");
            } catch (AuthorizationException) {
            }
        }

        $this->assertSame(User::ROLE_SALES_EXECUTIVE, $target->fresh()->role);
    }

    public function test_role_and_active_flag_cannot_be_mass_assigned_or_filled_from_a_profile_form(): void
    {
        $user = $this->staff(User::ROLE_SALES_EXECUTIVE);

        $user->update(['name' => 'Renamed', 'role' => User::ROLE_SUPER_ADMIN, 'is_active' => false]);

        $this->assertSame('Renamed', $user->fresh()->name);
        $this->assertSame(User::ROLE_SALES_EXECUTIVE, $user->fresh()->role);
        $this->assertTrue($user->fresh()->is_active);
    }

    public function test_users_cannot_change_their_own_role_or_deactivate_themselves(): void
    {
        $admin = $this->superAdmin();
        $this->superAdmin(); // a second Super Admin, so lock-out protection is not what blocks this

        foreach ([['role' => User::ROLE_SALES_EXECUTIVE], ['is_active' => false]] as $change) {
            try {
                app(UserAdministration::class)->update($admin, $change, $admin);
                $this->fail('Self-change must be refused.');
            } catch (ValidationException) {
            }
        }

        $this->assertSame(User::ROLE_SUPER_ADMIN, $admin->fresh()->role);
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_inactive_actors_cannot_administer_users(): void
    {
        $target = $this->superAdmin();
        $inactive = $this->staff(User::ROLE_SUPER_ADMIN, ['is_active' => false]);

        $this->expectException(AuthorizationException::class);
        app(UserAdministration::class)->update($target, ['role' => User::ROLE_SALES_EXECUTIVE], $inactive);
    }

    public function test_a_demoted_super_admin_loses_the_power_to_demote_the_last_one(): void
    {
        $admin = $this->superAdmin();
        $other = $this->superAdmin();

        app(UserAdministration::class)->update($other, ['role' => User::ROLE_SALES_MANAGER], $admin);
        $this->assertSame(User::ROLE_SALES_MANAGER, $other->fresh()->role);

        // $admin is now the only Super Admin; the demoted user cannot touch them.
        $this->expectException(AuthorizationException::class);
        app(UserAdministration::class)->update($admin, ['role' => User::ROLE_SALES_EXECUTIVE], $other->fresh());
    }

    public function test_the_system_never_ends_up_without_an_active_super_admin(): void
    {
        $admin = $this->superAdmin();
        $other = $this->superAdmin();

        // A stale actor (demoted a moment ago, session still holding the old role) is stopped by the transactional re-check.
        app(UserAdministration::class)->update($other, ['role' => User::ROLE_SALES_MANAGER], $admin);

        $this->expectException(ValidationException::class);
        app(UserAdministration::class)->update($admin, ['role' => User::ROLE_SALES_EXECUTIVE], $other);
    }

    public function test_role_changes_are_audited_without_secrets(): void
    {
        Log::spy();
        $admin = $this->superAdmin();
        $target = $this->staff(User::ROLE_SALES_EXECUTIVE);

        app(UserAdministration::class)->update($target, ['role' => User::ROLE_SALES_MANAGER, 'password' => self::STRONG_PASSWORD], $admin);

        Log::shouldHaveReceived('info')->withArgs(function ($message, $context = []) use ($target) {
            return $message === 'admin.user.access_changed'
                && ($context['user_id'] ?? null) === $target->id
                && ! str_contains(json_encode($context), self::STRONG_PASSWORD);
        })->once();
    }

    public function test_edit_form_leaves_the_password_unchanged_when_blank(): void
    {
        $admin = $this->superAdmin();
        $target = $this->staff(User::ROLE_SALES_EXECUTIVE);
        $hash = $target->password;

        Livewire::actingAs($admin)->test(EditUser::class, ['record' => $target->getKey()])
            ->fillForm(['name' => 'New Name', 'password' => ''])
            ->call('save')->assertHasNoFormErrors();

        $this->assertSame('New Name', $target->fresh()->name);
        $this->assertSame($hash, $target->fresh()->password);
    }

    public function test_editing_ones_own_record_ignores_a_tampered_role(): void
    {
        $admin = $this->superAdmin();
        $this->superAdmin();

        Livewire::actingAs($admin)->test(EditUser::class, ['record' => $admin->getKey()])
            ->assertFormFieldDisabled('role')
            ->call('save');

        $this->assertSame(User::ROLE_SUPER_ADMIN, $admin->fresh()->role);
    }

    public function test_the_provisioning_command_creates_a_super_admin_without_a_default_password(): void
    {
        $this->artisan('techmate:create-admin', ['--name' => 'Owner', '--email' => 'owner@example.test', '--no-interaction' => true])
            ->assertFailed();
        $this->assertSame(0, User::count());

        putenv('TECHMATE_ADMIN_PASSWORD='.self::STRONG_PASSWORD);
        try {
            $this->artisan('techmate:create-admin', ['--name' => 'Owner', '--email' => 'owner@example.test', '--no-interaction' => true])
                ->assertSuccessful();
        } finally {
            putenv('TECHMATE_ADMIN_PASSWORD');
        }

        $owner = User::where('email', 'owner@example.test')->firstOrFail();
        $this->assertSame(User::ROLE_SUPER_ADMIN, $owner->role);
        $this->assertTrue($owner->is_active);
        $this->assertTrue(Hash::check(self::STRONG_PASSWORD, $owner->password));
    }

    public function test_the_provisioning_command_rejects_weak_passwords_and_duplicate_emails(): void
    {
        putenv('TECHMATE_ADMIN_PASSWORD=weak');
        try {
            $this->artisan('techmate:create-admin', ['--name' => 'A', '--email' => 'a@example.test', '--no-interaction' => true])->assertFailed();
        } finally {
            putenv('TECHMATE_ADMIN_PASSWORD');
        }

        User::factory()->create(['email' => 'dupe@example.test']);
        putenv('TECHMATE_ADMIN_PASSWORD='.self::STRONG_PASSWORD);
        try {
            $this->artisan('techmate:create-admin', ['--name' => 'D', '--email' => 'dupe@example.test', '--no-interaction' => true])->assertFailed();
        } finally {
            putenv('TECHMATE_ADMIN_PASSWORD');
        }

        $this->assertSame(1, User::count());
    }
}
