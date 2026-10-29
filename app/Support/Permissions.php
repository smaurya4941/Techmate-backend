<?php

namespace App\Support;

use App\Models\User;

/**
 * Single source of truth for the role/permission matrix (see docs/admin-panel.md and
 * docs/sales-workflow.md). Policies and Filament resources authorize through
 * User::hasPermission(); roles are stored on users.role and are never mass-assignable.
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

    /** Closing a deal as won commits the company to an order, so it is a manager-level decision. */
    public const LEADS_MARK_WON = 'leads.mark_won';

    public const LEADS_MARK_LOST = 'leads.mark_lost';

    public const LEADS_REOPEN = 'leads.reopen';

    public const QUOTATIONS_VIEW_ALL = 'quotations.view_all';

    public const QUOTATIONS_VIEW_OWN = 'quotations.view_own';

    /** Prepare, submit, revise, send and record the customer's response. */
    public const QUOTATIONS_MANAGE = 'quotations.manage';

    public const QUOTATIONS_APPROVE = 'quotations.approve';

    public const ORDERS_VIEW_ALL = 'orders.view_all';

    public const ORDERS_VIEW_OWN = 'orders.view_own';

    /** Confirm, progress and cancel orders; record installation. */
    public const ORDERS_MANAGE = 'orders.manage';

    public const PAYMENTS_RECORD = 'payments.record';

    public const PAYMENTS_VERIFY = 'payments.verify';

    public const REPORTS_TEAM = 'reports.team';

    public const REPORTS_OWN = 'reports.own';

    /** Notification outbox and audit trail. */
    public const SYSTEM_MONITOR = 'system.monitor';

    /** @var array<string, list<string>> */
    public const MATRIX = [
        User::ROLE_SUPER_ADMIN => [
            self::USERS_MANAGE, self::SETTINGS_MANAGE, self::CATALOGUE_MANAGE, self::CATALOGUE_DELETE,
            self::CMS_MANAGE, self::LEADS_VIEW_ALL, self::LEADS_ASSIGN, self::LEADS_UPDATE,
            self::LEADS_MARK_WON, self::LEADS_MARK_LOST, self::LEADS_REOPEN,
            self::QUOTATIONS_VIEW_ALL, self::QUOTATIONS_MANAGE, self::QUOTATIONS_APPROVE,
            self::ORDERS_VIEW_ALL, self::ORDERS_MANAGE, self::PAYMENTS_RECORD, self::PAYMENTS_VERIFY,
            self::REPORTS_TEAM, self::SYSTEM_MONITOR,
        ],
        User::ROLE_CATALOGUE_MANAGER => [
            self::CATALOGUE_MANAGE, self::CMS_MANAGE,
        ],
        User::ROLE_SALES_EXECUTIVE => [
            self::LEADS_UPDATE, self::LEADS_VIEW_OWN, self::LEADS_MARK_LOST,
            self::QUOTATIONS_VIEW_OWN, self::QUOTATIONS_MANAGE,
            self::ORDERS_VIEW_OWN, self::REPORTS_OWN,
        ],
        User::ROLE_SALES_MANAGER => [
            self::LEADS_UPDATE, self::LEADS_VIEW_ALL, self::LEADS_ASSIGN,
            self::LEADS_MARK_WON, self::LEADS_MARK_LOST, self::LEADS_REOPEN,
            self::QUOTATIONS_VIEW_ALL, self::QUOTATIONS_MANAGE, self::QUOTATIONS_APPROVE,
            self::ORDERS_VIEW_ALL, self::ORDERS_MANAGE, self::PAYMENTS_RECORD, self::PAYMENTS_VERIFY,
            self::REPORTS_TEAM,
        ],
        User::ROLE_OPERATIONS => [
            self::ORDERS_VIEW_ALL, self::ORDERS_MANAGE, self::PAYMENTS_RECORD,
        ],
    ];

    public static function roleHas(string $role, string $permission): bool
    {
        return in_array($permission, self::MATRIX[$role] ?? [], true);
    }
}
