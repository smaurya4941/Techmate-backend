<?php

namespace App\Services;

use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The only place staff accounts are created or have their role / active flag
 * changed. role and is_active are not mass-assignable, so privileged changes
 * cannot arrive through a profile form, a crafted request or a stray fill().
 */
class UserAdministration
{
    /** @param  array{name: string, email: string, phone?: ?string, password: string, role: string, is_active?: bool}  $data */
    public function create(array $data, User $actor): User
    {
        Gate::forUser($actor)->authorize('create', User::class);
        $this->assertRole($data['role'] ?? null);

        return DB::transaction(function () use ($data) {
            $user = new User(collect($data)->only(['name', 'email', 'phone', 'password'])->all());
            $user->forceFill([
                'role' => $data['role'],
                'is_active' => $data['is_active'] ?? true,
            ])->save();

            AuditLog::record('user.created', ['user_id' => $user->id, 'role' => $user->role]);

            return $user;
        });
    }

    /** @param  array<string, mixed>  $data  password is applied only when filled. */
    public function update(User $target, array $data, User $actor): User
    {
        Gate::forUser($actor)->authorize('update', $target);

        return DB::transaction(function () use ($target, $data, $actor) {
            $target = User::query()->lockForUpdate()->findOrFail($target->getKey());

            $role = $data['role'] ?? $target->role;
            $active = (bool) ($data['is_active'] ?? $target->is_active);
            $this->assertRole($role);

            if ($target->is($actor) && ($role !== $target->role || ! $active)) {
                throw ValidationException::withMessages([
                    'role' => 'You cannot change your own role or deactivate your own account.',
                ]);
            }

            $losesSuperAdmin = $target->role === User::ROLE_SUPER_ADMIN && $target->is_active
                && ($role !== User::ROLE_SUPER_ADMIN || ! $active);
            $otherSuperAdmins = User::query()->active()->where('role', User::ROLE_SUPER_ADMIN)->whereKeyNot($target->id)->exists();

            if ($losesSuperAdmin && ! $otherSuperAdmins) {
                throw ValidationException::withMessages(['role' => 'There must always be at least one active Super Admin.']);
            }

            $before = ['role' => $target->role, 'is_active' => $target->is_active];

            $target->fill(collect($data)->only(['name', 'email', 'phone'])->all());
            if (filled($data['password'] ?? null)) {
                $target->password = $data['password'];
            }
            $target->forceFill(['role' => $role, 'is_active' => $active])->save();

            if ($before !== ['role' => $target->role, 'is_active' => $target->is_active]) {
                AuditLog::record('user.access_changed', [
                    'user_id' => $target->id,
                    'role' => [$before['role'], $target->role],
                    'is_active' => [$before['is_active'], $target->is_active],
                ]);
            }
            if ($target->wasChanged('password')) {
                AuditLog::record('user.password_changed', ['user_id' => $target->id]);
            }

            return $target;
        });
    }

    private function assertRole(?string $role): void
    {
        if (! in_array($role, User::ROLES, true)) {
            throw ValidationException::withMessages(['role' => 'Unknown role.']);
        }
    }
}
