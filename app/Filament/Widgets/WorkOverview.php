<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\FollowUps\FollowUpResource;
use App\Models\Lead;
use App\Models\NotificationOutbox;
use App\Models\Order;
use App\Models\Product;
use App\Models\Quotation;
use App\Policies\LeadPolicy;
use App\Policies\OrderPolicy;
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
            $all = $user->hasPermission(Permissions::LEADS_VIEW_ALL);
            $leads = fn () => LeadPolicy::scope(Lead::query(), $user);

            $stats[] = Stat::make('New enquiries', $leads()->where('status', 'new')->count())
                ->description($all ? 'All unworked leads' : 'Assigned to you')->color('info');

            if ($all) {
                $unassigned = $leads()->whereNull('assigned_to')->whereIn('status', Lead::OPEN_STATUSES)->count();
                $stats[] = Stat::make('Unassigned leads', $unassigned)->description('Need an owner')->color($unassigned > 0 ? 'warning' : 'success');
            }

            $stats[] = Stat::make('Open pipeline', $leads()->whereIn('status', ['assigned', 'contacted', 'qualified', 'quotation_in_progress', 'quotation_sent', 'negotiation'])->count())->color('primary');

            $followUps = fn () => FollowUpResource::getEloquentQuery();
            $overdue = $followUps()->overdue()->count();
            $stats[] = Stat::make('Overdue follow-ups', $overdue)->color($overdue > 0 ? 'danger' : 'success')
                ->url(FollowUpResource::getUrl('index'));
            $stats[] = Stat::make('Follow-ups due today', $followUps()->dueToday()->count())->color('warning');
        }

        if ($user->hasPermission(Permissions::QUOTATIONS_VIEW_ALL) || $user->hasPermission(Permissions::QUOTATIONS_VIEW_OWN)) {
            $quotations = fn () => QuotationPolicy::scope(Quotation::query(), $user)->where('is_current', true);
            if ($user->hasPermission(Permissions::QUOTATIONS_APPROVE)) {
                $pending = $quotations()->where('status', 'pending_approval')->count();
                $stats[] = Stat::make('Quotations awaiting approval', $pending)->color($pending > 0 ? 'warning' : 'success');
            }
            $stats[] = Stat::make('Quotations awaiting customer', $quotations()->where('status', 'sent')->count());
        }

        if ($user->hasPermission(Permissions::ORDERS_VIEW_ALL) || $user->hasPermission(Permissions::ORDERS_VIEW_OWN)) {
            $orders = fn () => OrderPolicy::scope(Order::query(), $user);
            $stats[] = Stat::make('Orders awaiting confirmation', $orders()->where('status', 'pending_confirmation')->count())->color('warning');
            $stats[] = Stat::make('Orders in fulfilment', $orders()->whereIn('status', ['confirmed', 'processing', 'ready_for_dispatch', 'dispatched'])->count());
        }

        if ($user->hasPermission(Permissions::SYSTEM_MONITOR)) {
            $failed = NotificationOutbox::query()->where('status', 'failed')->count();
            $stats[] = Stat::make('Failed e-mails', $failed)->color($failed > 0 ? 'danger' : 'success')->description('Retry under Notifications');
        }

        if ($user->hasPermission(Permissions::CATALOGUE_MANAGE)) {
            $stats[] = Stat::make('Published products', Product::query()->where('status', Product::STATUS_PUBLISHED)->count())->color('success');
            $stats[] = Stat::make('Draft products', Product::query()->where('status', Product::STATUS_DRAFT)->count())->color('warning');
        }

        return $stats;
    }
}
