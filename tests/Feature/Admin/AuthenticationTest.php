<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Filament\Auth\Pages\Login;
use Livewire\Livewire;

class AuthenticationTest extends AdminTestCase
{
    public function test_guests_are_redirected_to_the_admin_login(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->get('/admin/products')->assertRedirect('/admin/login');
        $this->get('/admin/leads')->assertRedirect('/admin/login');
        $this->get('/admin/users')->assertRedirect('/admin/login');
    }

    public function test_guests_cannot_download_private_documents(): void
    {
        $this->get('/admin/product-documents/1/download')->assertRedirect('/admin/login');
    }

    public function test_there_is_no_public_registration(): void
    {
        $this->get('/admin/register')->assertNotFound();
    }

    public function test_staff_can_sign_in_with_valid_credentials(): void
    {
        $user = $this->superAdmin();

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'password'])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_wrong_password_is_rejected(): void
    {
        $user = $this->superAdmin();

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'wrong-password'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest();
    }

    public function test_inactive_accounts_cannot_sign_in(): void
    {
        $user = $this->staff(User::ROLE_SUPER_ADMIN, ['is_active' => false]);

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'password'])
            ->call('authenticate');

        $this->assertGuest();
    }

    public function test_an_account_deactivated_mid_session_loses_panel_access(): void
    {
        $user = $this->superAdmin();
        $this->actingAs($user)->get('/admin')->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->actingAs($user->fresh())->get('/admin')->assertForbidden();
    }

    public function test_login_is_throttled_after_repeated_failures(): void
    {
        $user = $this->superAdmin();
        $component = Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'wrong-password']);

        foreach (range(1, 6) as $attempt) {
            $component->call('authenticate');
        }

        $component->assertNotified();
        $this->assertGuest();

        // Even the correct password is refused while throttled.
        $component->fillForm(['email' => $user->email, 'password' => 'password'])->call('authenticate');
        $this->assertGuest();
    }

    public function test_every_role_can_open_the_dashboard(): void
    {
        foreach (User::ROLES as $role) {
            $this->actingAs($this->staff($role))->get('/admin')->assertOk();
        }
    }
}
