@php
    $report = $this->getReport();
    $fmtMinutes = function (?int $m) {
        if ($m === null) return '-';
        if ($m < 60) return $m.' min';
        if ($m < 1440) return round($m / 60, 1).' h';
        return round($m / 1440, 1).' days';
    };
    $money = function (array $byCurrency) {
        if (! $byCurrency) return '-';
        return collect($byCurrency)->map(fn ($v, $c) => $c.' '.number_format((float) $v, 2))->implode(' · ');
    };
    $pct = fn ($v) => $v === null ? '-' : $v.'%';
@endphp

<x-filament-panels::page>
    <div class="space-y-6">
        <x-filament::section>
            {{ $this->form }}
            <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">{{ $this->scopeNote() }} Dates use the {{ config('techmate.business_timezone') }} timezone.</p>
        </x-filament::section>

        @if (! $report)
            <x-filament::section>Choose a valid date range.</x-filament::section>
        @else
            {{-- Headline metrics --}}
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach ([
                    ['Enquiries', $report['leads']['total'], $report['leads']['spam'].' marked spam'],
                    ['Median first response', $fmtMinutes($report['response']['median_minutes']), $report['response']['responded'].' responded · '.$report['response']['unresponded'].' waiting'],
                    ['Quotations sent', $report['quotations']['sent'], $money($report['quotations']['sent_value'])],
                    ['Win rate', $pct($report['win_loss']['win_rate_percent']), $report['win_loss']['won'].' won · '.$report['win_loss']['lost'].' lost'],
                ] as [$label, $value, $hint])
                    <x-filament::section>
                        <div class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</div>
                        <div class="mt-1 text-3xl font-semibold tracking-tight">{{ $value }}</div>
                        <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ $hint }}</div>
                    </x-filament::section>
                @endforeach
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <x-filament::section heading="Leads by source" description="Where enquiries come from">
                    @include('filament.pages.partials.report-table', ['rows' => $report['by_source'], 'cols' => ['label' => 'Source', 'count' => 'Leads']])
                </x-filament::section>
                <x-filament::section heading="Leads by equipment category" description="Leads that asked for products in each category">
                    @include('filament.pages.partials.report-table', ['rows' => $report['by_category'], 'cols' => ['label' => 'Category', 'count' => 'Leads']])
                </x-filament::section>
            </div>

            <x-filament::section heading="Leads by executive" description="Leads created in the period, by current owner">
                @include('filament.pages.partials.report-table', ['rows' => $report['by_executive'], 'cols' => ['name' => 'Owner', 'total' => 'Leads', 'open' => 'Open', 'won' => 'Won', 'lost' => 'Lost']])
            </x-filament::section>

            <div class="grid gap-6 lg:grid-cols-2">
                <x-filament::section heading="Follow-up performance" description="Tasks due in the period">
                    @include('filament.pages.partials.report-table', ['rows' => [
                        ['label' => 'Due in period', 'count' => $report['follow_ups']['due']],
                        ['label' => 'Completed on time', 'count' => $report['follow_ups']['completed_on_time']],
                        ['label' => 'Completed late', 'count' => $report['follow_ups']['completed_late']],
                        ['label' => 'Still open and overdue', 'count' => $report['follow_ups']['overdue_open']],
                        ['label' => 'Cancelled', 'count' => $report['follow_ups']['cancelled']],
                    ], 'cols' => ['label' => 'Measure', 'count' => 'Tasks']])
                </x-filament::section>
                <x-filament::section heading="Quotations" description="Versions created, approved and sent in the period">
                    @include('filament.pages.partials.report-table', ['rows' => [
                        ['label' => 'Created', 'count' => $report['quotations']['created']],
                        ['label' => 'Approved', 'count' => $report['quotations']['approved']],
                        ['label' => 'Sent', 'count' => $report['quotations']['sent']],
                        ['label' => 'Of those sent, now accepted', 'count' => $report['quotations']['accepted']],
                        ['label' => 'Quotation conversion', 'count' => $pct($report['quotations']['conversion_percent'])],
                    ], 'cols' => ['label' => 'Measure', 'count' => 'Value']])
                </x-filament::section>
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <x-filament::section heading="Win / loss" description="Leads closed in the period">
                    @include('filament.pages.partials.report-table', ['rows' => $report['win_loss']['lost_reasons'], 'cols' => ['label' => 'Lost reason', 'count' => 'Leads'], 'empty' => 'No lost leads in this period.'])
                    <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
                        Lead conversion: {{ $report['conversion']['won'] }} of {{ $report['conversion']['eligible'] }} genuine enquiries won ({{ $pct($report['conversion']['percent']) }}).
                    </p>
                </x-filament::section>
                <x-filament::section heading="Orders" description="Confirmed in the period">
                    @include('filament.pages.partials.report-table', ['rows' => [
                        ['label' => 'Orders (excluding cancelled)', 'count' => $report['orders']['count']],
                        ['label' => 'Order value', 'count' => $money($report['orders']['value'])],
                        ['label' => 'Cancelled', 'count' => $report['orders']['cancelled']],
                    ], 'cols' => ['label' => 'Measure', 'count' => 'Value']])
                </x-filament::section>
            </div>

            <x-filament::section heading="Outstanding payments" description="Current balance on all open orders: order value minus payments not voided">
                <div class="mb-3 text-lg font-semibold">{{ $money($report['outstanding']['totals']) }}</div>
                @include('filament.pages.partials.report-table', ['rows' => $report['outstanding']['orders'], 'cols' => ['order_number' => 'Order', 'customer' => 'Customer', 'total' => 'Order value', 'balance' => 'Balance', 'payment_status' => 'Payment'], 'empty' => 'Nothing outstanding.'])
            </x-filament::section>

            <x-filament::section heading="How these are calculated" collapsible collapsed>
                <ul class="list-disc space-y-1 pl-5 text-sm text-gray-600 dark:text-gray-300">
                    <li><strong>Enquiries:</strong> leads created in the period.</li>
                    <li><strong>First response:</strong> from the customer's submission to the first logged call, e-mail or meeting by staff. Notes and status changes do not count. Leads still waiting are excluded from the median.</li>
                    <li><strong>Quotation conversion:</strong> of the quotation versions sent in the period, the share now accepted.</li>
                    <li><strong>Win rate:</strong> won ÷ (won + lost) among leads closed in the period.</li>
                    <li><strong>Lead conversion:</strong> genuine (non-spam) leads created in the period that are now won.</li>
                    <li><strong>Order value:</strong> grand total (tax and freight included) of non-cancelled orders confirmed in the period, never mixed across currencies.</li>
                    <li><strong>Outstanding:</strong> order value minus every payment that has not been voided, for all non-cancelled orders.</li>
                </ul>
            </x-filament::section>
        @endif
    </div>
</x-filament-panels::page>
