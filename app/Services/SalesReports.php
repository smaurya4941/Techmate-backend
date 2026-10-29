<?php

namespace App\Services;

use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\Order;
use App\Models\Quotation;
use App\Models\User;
use App\Policies\LeadPolicy;
use App\Support\Permissions;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sales metrics, computed directly from the stored records. Every figure is scoped to what
 * the user may see (executives: their own leads; managers and admins: the team), uses the
 * business timezone for the date range, and follows the definitions below.
 *
 * DEFINITIONS (also in docs/sales-workflow.md)
 *  Lead count          leads created in the period (spam shown separately, excluded from rates).
 *  First response      first_response_at - submitted_at (falls back to created_at) for leads created in the
 *                      period, where first_response_at is the earliest logged call, e-mail or meeting by
 *                      staff. Notes and status changes are not contact. Unresponded leads are excluded
 *                      from the average and counted separately.
 *  Quotations sent     quotation versions whose sent_at falls in the period; value is their grand_total
 *                      per currency (tax and freight included, never mixed across currencies).
 *  Quotation conversion  of the versions sent in the period, the share now accepted.
 *  Win rate            won / (won + lost) among leads closed (closed_at) in the period.
 *  Lead conversion     of non-spam leads created in the period, the share that is won (any time since).
 *  Order value         grand_total of non-cancelled orders with ordered_at in the period, per currency.
 *  Outstanding         for every non-cancelled order, grand_total - payments not voided; current, not date-bound.
 *  Follow-ups          tasks whose due time falls in the period; "on time" means completed at or before due.
 */
class SalesReports
{
    private CarbonImmutable $from;

    private CarbonImmutable $to;

    public function __construct(private readonly User $user, string $from, string $to)
    {
        $tz = config('techmate.business_timezone');
        $this->from = CarbonImmutable::parse($from, $tz)->startOfDay()->utc();
        $this->to = CarbonImmutable::parse($to, $tz)->endOfDay()->utc();
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        return [
            'period' => [
                'from' => $this->from->timezone(config('techmate.business_timezone'))->toDateString(),
                'to' => $this->to->timezone(config('techmate.business_timezone'))->toDateString(),
            ],
            'leads' => $this->leadVolume(),
            'by_source' => $this->bySource(),
            'by_category' => $this->byCategory(),
            'by_executive' => $this->byExecutive(),
            'response' => $this->responsiveness(),
            'follow_ups' => $this->followUps(),
            'quotations' => $this->quotations(),
            'win_loss' => $this->winLoss(),
            'conversion' => $this->conversion(),
            'orders' => $this->orders(),
            'outstanding' => $this->outstanding(),
        ];
    }

    private function leadsCreated(): Builder
    {
        return LeadPolicy::scope(Lead::query(), $this->user)->whereBetween('created_at', [$this->from, $this->to]);
    }

    /** @return array{total: int, spam: int, genuine: int} */
    private function leadVolume(): array
    {
        $total = $this->leadsCreated()->count();
        $spam = $this->leadsCreated()->where('status', 'spam')->count();

        return ['total' => $total, 'spam' => $spam, 'genuine' => $total - $spam];
    }

    /** @return list<array{label: string, count: int}> */
    private function bySource(): array
    {
        return $this->leadsCreated()->selectRaw('COALESCE(source, ?) as label, COUNT(*) as aggregate', ['unknown'])
            ->groupBy('source')->orderByDesc('aggregate')->get()
            ->map(fn ($r) => ['label' => (string) $r->label, 'count' => (int) $r->aggregate])->all();
    }

    /** @return list<array{label: string, count: int}> */
    private function byCategory(): array
    {
        $leadIds = $this->leadsCreated()->select('leads.id');

        return DB::table('lead_products')
            ->whereIn('lead_products.lead_id', $leadIds)
            ->leftJoin('products', 'products.id', '=', 'lead_products.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->selectRaw('COALESCE(categories.name, ?) as label, COUNT(DISTINCT lead_products.lead_id) as aggregate', ['Custom / unlisted'])
            ->groupBy('categories.name')->orderByDesc('aggregate')->get()
            ->map(fn ($r) => ['label' => (string) $r->label, 'count' => (int) $r->aggregate])->all();
    }

    /** @return list<array{name: string, total: int, open: int, won: int, lost: int}> */
    private function byExecutive(): array
    {
        $names = User::query()->pluck('name', 'id');

        return $this->leadsCreated()->get(['assigned_to', 'status'])
            ->groupBy(fn ($l) => $l->assigned_to ?? 0)
            ->map(fn (Collection $leads, $id) => [
                'name' => $id ? ($names[$id] ?? "User #{$id}") : 'Unassigned',
                'total' => $leads->count(),
                'open' => $leads->whereIn('status', Lead::OPEN_STATUSES)->count(),
                'won' => $leads->where('status', 'won')->count(),
                'lost' => $leads->where('status', 'lost')->count(),
            ])->sortByDesc('total')->values()->all();
    }

    /** @return array{responded: int, unresponded: int, average_minutes: ?int, median_minutes: ?int} */
    private function responsiveness(): array
    {
        $leads = $this->leadsCreated()->where('status', '!=', 'spam')->get(['submitted_at', 'created_at', 'first_response_at']);

        $minutes = $leads->filter(fn ($l) => $l->first_response_at)
            ->map(fn ($l) => max(0, (int) round(($l->submitted_at ?? $l->created_at)->diffInSeconds($l->first_response_at, false) / 60)))
            ->sort()->values();

        return [
            'responded' => $minutes->count(),
            'unresponded' => $leads->count() - $minutes->count(),
            'average_minutes' => $minutes->isEmpty() ? null : (int) round($minutes->avg()),
            'median_minutes' => $minutes->isEmpty() ? null : (int) $minutes[intdiv($minutes->count() - 1, 2)],
        ];
    }

    /** @return array{due: int, completed_on_time: int, completed_late: int, overdue_open: int, cancelled: int} */
    private function followUps(): array
    {
        $tasks = FollowUp::query()
            ->whereHas('lead', fn (Builder $lead) => LeadPolicy::scope($lead, $this->user))
            ->when(! $this->user->hasPermission(Permissions::REPORTS_TEAM), fn (Builder $q) => $q->where('assigned_to', $this->user->getKey()))
            ->whereBetween('due_at', [$this->from, $this->to])
            ->get(['status', 'due_at', 'completed_at']);

        $completed = $tasks->where('status', 'completed');

        return [
            'due' => $tasks->count(),
            'completed_on_time' => $completed->filter(fn ($t) => $t->completed_at && $t->completed_at->lte($t->due_at))->count(),
            'completed_late' => $completed->filter(fn ($t) => $t->completed_at && $t->completed_at->gt($t->due_at))->count(),
            'overdue_open' => $tasks->filter(fn ($t) => in_array($t->status, FollowUp::ACTIVE_STATUSES, true) && $t->due_at->isPast())->count(),
            'cancelled' => $tasks->where('status', 'cancelled')->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function quotations(): array
    {
        $scope = fn () => Quotation::query()->whereHas('lead', fn (Builder $lead) => LeadPolicy::scope($lead, $this->user));

        $sent = $scope()->whereBetween('sent_at', [$this->from, $this->to])->get(['status', 'currency_code', 'grand_total']);
        $accepted = $sent->where('status', 'accepted');

        return [
            'created' => $scope()->whereBetween('created_at', [$this->from, $this->to])->count(),
            'approved' => $scope()->whereBetween('approved_at', [$this->from, $this->to])->count(),
            'sent' => $sent->count(),
            'sent_value' => $this->sumByCurrency($sent, 'currency_code', 'grand_total'),
            'accepted' => $accepted->count(),
            'conversion_percent' => $sent->isEmpty() ? null : round($accepted->count() / $sent->count() * 100, 1),
        ];
    }

    /** @return array<string, mixed> */
    private function winLoss(): array
    {
        $closed = LeadPolicy::scope(Lead::query(), $this->user)->whereIn('status', ['won', 'lost'])
            ->whereBetween('closed_at', [$this->from, $this->to])->get(['status', 'lost_reason']);

        $won = $closed->where('status', 'won')->count();
        $lost = $closed->where('status', 'lost');

        return [
            'won' => $won,
            'lost' => $lost->count(),
            'win_rate_percent' => $closed->isEmpty() ? null : round($won / $closed->count() * 100, 1),
            'lost_reasons' => $lost->groupBy(fn ($l) => Lead::LOST_REASONS[$l->lost_reason] ?? 'Not recorded')
                ->map(fn ($g, $label) => ['label' => $label, 'count' => $g->count()])->sortByDesc('count')->values()->all(),
        ];
    }

    /** @return array{eligible: int, won: int, percent: ?float} */
    private function conversion(): array
    {
        $eligible = $this->leadsCreated()->where('status', '!=', 'spam');
        $count = (clone $eligible)->count();
        $won = (clone $eligible)->where('status', 'won')->count();

        return ['eligible' => $count, 'won' => $won, 'percent' => $count === 0 ? null : round($won / $count * 100, 1)];
    }

    private function scopedOrders(): Builder
    {
        return Order::query()->whereHas('lead', fn (Builder $lead) => LeadPolicy::scope($lead, $this->user));
    }

    /** @return array<string, mixed> */
    private function orders(): array
    {
        $orders = $this->scopedOrders()->whereBetween('ordered_at', [$this->from, $this->to])->get(['status', 'currency_code', 'grand_total']);
        $live = $orders->where('status', '!=', 'cancelled');

        return [
            'count' => $live->count(),
            'value' => $this->sumByCurrency($live, 'currency_code', 'grand_total'),
            'cancelled' => $orders->where('status', 'cancelled')->count(),
        ];
    }

    /** @return array{totals: array<string, string>, orders: list<array<string, mixed>>} */
    private function outstanding(): array
    {
        $orders = $this->scopedOrders()->where('status', '!=', 'cancelled')->with(['payments', 'lead:id,public_reference'])->get()
            ->map(fn (Order $o) => ['order' => $o, 'balance' => $o->balanceDue()])
            ->filter(fn ($row) => BigDecimal::of($row['balance'])->isPositive());

        return [
            'totals' => $this->sumByCurrency($orders->map(fn ($r) => (object) ['currency_code' => $r['order']->currency_code, 'balance' => $r['balance']]), 'currency_code', 'balance'),
            'orders' => $orders->sortByDesc(fn ($r) => (float) $r['balance'])->take(10)->map(fn ($r) => [
                'order_number' => $r['order']->order_number,
                'customer' => $r['order']->customer_name_snapshot,
                'currency' => $r['order']->currency_code,
                'total' => (string) $r['order']->grand_total,
                'balance' => $r['balance'],
                'payment_status' => $r['order']->paymentStatus(),
            ])->values()->all(),
        ];
    }

    /**
     * @param  Collection<int, mixed>  $rows
     * @return array<string, string> currency => decimal string
     */
    private function sumByCurrency(Collection $rows, string $currencyField, string $amountField): array
    {
        $sums = [];

        foreach ($rows as $row) {
            $currency = $row->{$currencyField};
            $sums[$currency] = (string) BigDecimal::of($sums[$currency] ?? '0')->plus((string) $row->{$amountField})->toScale(2);
        }

        ksort($sums);

        return $sums;
    }
}
