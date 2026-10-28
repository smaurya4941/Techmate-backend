<?php

namespace App\Models;

use App\Support\Permissions;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLE_CATALOGUE_MANAGER = 'catalogue_manager';

    public const ROLE_SALES_EXECUTIVE = 'sales_executive';

    public const ROLE_SALES_MANAGER = 'sales_manager';

    public const ROLES = [
        self::ROLE_SUPER_ADMIN,
        self::ROLE_CATALOGUE_MANAGER,
        self::ROLE_SALES_EXECUTIVE,
        self::ROLE_SALES_MANAGER,
    ];

    public const ROLE_LABELS = [
        self::ROLE_SUPER_ADMIN => 'Super Admin',
        self::ROLE_CATALOGUE_MANAGER => 'Catalogue Manager',
        self::ROLE_SALES_EXECUTIVE => 'Sales Executive',
        self::ROLE_SALES_MANAGER => 'Sales Manager',
    ];

    /**
     * Role, is_active and last_login_at are privileged and deliberately not
     * mass-assignable; set them explicitly in trusted code.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /** Only active staff with a recognised role may enter /admin. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && in_array($this->role, self::ROLES, true);
    }

    public function hasPermission(string $permission): bool
    {
        return $this->is_active && Permissions::roleHas((string) $this->role, $permission);
    }

    public function isSuperAdmin(): bool
    {
        return $this->is_active && $this->role === self::ROLE_SUPER_ADMIN;
    }

    public function roleLabel(): string
    {
        return self::ROLE_LABELS[$this->role] ?? (string) $this->role;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Staff who can be assigned a lead. */
    public function scopeAssignable(Builder $query): Builder
    {
        return $query->active()->whereIn('role', [
            self::ROLE_SALES_EXECUTIVE, self::ROLE_SALES_MANAGER, self::ROLE_SUPER_ADMIN,
        ]);
    }

    public function assignedLeads(): HasMany
    {
        return $this->hasMany(Lead::class, 'assigned_to');
    }

    public function assignedFollowUps(): HasMany
    {
        return $this->hasMany(FollowUp::class, 'assigned_to');
    }

    public function leadActivities(): HasMany
    {
        return $this->hasMany(LeadActivity::class);
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class, 'created_by');
    }
}
