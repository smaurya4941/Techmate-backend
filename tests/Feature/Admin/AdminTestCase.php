<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Filament\Facades\Filament;
use Tests\DatabaseTestCase;

abstract class AdminTestCase extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    protected function staff(string $role, array $attributes = []): User
    {
        return User::factory()->create(['role' => $role, ...$attributes]);
    }

    protected function superAdmin(): User
    {
        return $this->staff(User::ROLE_SUPER_ADMIN);
    }

    protected function catalogueManager(): User
    {
        return $this->staff(User::ROLE_CATALOGUE_MANAGER);
    }
}
