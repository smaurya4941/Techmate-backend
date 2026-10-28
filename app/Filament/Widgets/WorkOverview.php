<?php

namespace App\Filament\Widgets;

use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Product;
use App\Models\Quotation;
use App\Policies\LeadPolicy;
use App\Policies\QuotationPolicy;
use App\Support\Permissions;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/** Role-aware counters; every figure is computed through the same scoped queries as the resources. */
class WorkOverview extends StatsOverviewWidget
{
    protected ?string $heading = 'At a glance';

    protected function getStats(): array
    {
        $user = auth()->user();
        $stats = [];

        if ($user->hasPermission(Permissions::LEADS_VIEW_ALL) || $user->hasPermission(Permissions::LEADS_VIEW_OWN)) {
            $leads = fn () => LeadPolicy::scope(Lead::query(), $user);
            $stats[] = Stat::make('New enquiries', $leads()->where('status', 'new')->count())
                ->description($user->hasPermission(Permissions::LEADS_VIEW_ALL) ? 'All unworked leads' : 'Assigned to you')
                ->color('info');
            $stats[] = Stat::make('Open pipeline', $leads()->whereIn('status', ['contacted', 'qualified', 'proposal'])->count())->color('primary');
            $overdue = FollowUp::query()->overdue()
                ->whereHas('lead', fn ($q) => LeadPolicy::scope($q, $user))
                ->when(! $user->hasPermission(Permissions::LEADS_VIEW_ALL), fn ($q) => $q->where('assigned_to', $user->id))
                ->count();
            $stats[] = Stat::make('Overdue follow-ups', $overdue)->color($overdue > 0 ? 'danger' : 'success');
        }

        if ($user->hasPermission(Permissions::QUOTATIONS_VIEW_ALL) || $user->hasPermission(Permissions::QUOTATIONS_VIEW_OWN)) {
            $stats[] = Stat::make('Quotations awaiting response', QuotationPolicy::scope(Quotation::query(), $user)->where('status', 'sent')->count());
        }

        if ($user->hasPermission(Permissions::CATALOGUE_MANAGE)) {
            $stats[] = Stat::make('Published products', Product::query()->where('status', Product::STATUS_PUBLISHED)->count())->color('success');
            $stats[] = Stat::make('Draft products', Product::query()->where('status', Product::STATUS_DRAFT)->count())->color('warning');
        }

        return $stats;
    }
}
