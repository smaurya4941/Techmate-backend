<?php

namespace App\Support;

use App\Models\User;

/**
 * Single source of truth for the role/permission matrix (see docs/admin-panel.md).
 * Policies and Filament resources authorize through User::hasPermission(); roles
 * are stored on users.role and are never mass-assignable.
 */
final class Permissions
{
    public const USERS_MANAGE = 'users.manage';

    public const SETTINGS_MANAGE = 'settings.manage';

    public const CATALOGUE_MANAGE = 'catalogue.manage';

    /** Hard-delete / restore catalogue rows. Everyone else archives or unpublishes. */
    public const CATALOGUE_DELETE = 'catalogue.delete';

    public const CMS_MANAGE = 'cms.manage';

    public const LEADS_VIEW_ALL = 'leads.view_all';

    public const LEADS_VIEW_OWN = 'leads.view_own';

    public const LEADS_ASSIGN = 'leads.assign';

    public const LEADS_UPDATE = 'leads.update';

    public const QUOTATIONS_VIEW_ALL = 'quotations.view_all';

    public const QUOTATIONS_VIEW_OWN = 'quotations.view_own';

    public const QUOTATIONS_UPDATE_STATUS = 'quotations.update_status';

    public const REPORTS_TEAM = 'reports.team';

    private const SALES_COMMON = [self::LEADS_UPDATE];

    /** @var array<string, list<string>> */
    public const MATRIX = [
        User::ROLE_SUPER_ADMIN => [
            self::USERS_MANAGE, self::SETTINGS_MANAGE, self::CATALOGUE_MANAGE, self::CATALOGUE_DELETE,
            self::CMS_MANAGE, self::LEADS_VIEW_ALL, self::LEADS_ASSIGN, self::LEADS_UPDATE,
            self::QUOTATIONS_VIEW_ALL, self::QUOTATIONS_UPDATE_STATUS, self::REPORTS_TEAM,
        ],
        User::ROLE_CATALOGUE_MANAGER => [
            self::CATALOGUE_MANAGE, self::CMS_MANAGE,
        ],
        User::ROLE_SALES_EXECUTIVE => [
            ...self::SALES_COMMON, self::LEADS_VIEW_OWN, self::QUOTATIONS_VIEW_OWN,
        ],
        User::ROLE_SALES_MANAGER => [
            ...self::SALES_COMMON, self::LEADS_VIEW_ALL, self::LEADS_ASSIGN,
            self::QUOTATIONS_VIEW_ALL, self::QUOTATIONS_UPDATE_STATUS, self::REPORTS_TEAM,
        ],
    ];

    public static function roleHas(string $role, string $permission): bool
    {
        return in_array($permission, self::MATRIX[$role] ?? [], true);
    }
}
