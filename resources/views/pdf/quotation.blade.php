<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $q->quotation_number }}</title>
<style>
    @page { margin: 28px 34px 40px; }
    * { box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1c2733; line-height: 1.45; }
    h1 { font-size: 20px; margin: 0; color: #174A83; letter-spacing: .5px; }
    h2 { font-size: 10px; margin: 18px 0 6px; text-transform: uppercase; letter-spacing: .8px; color: #174A83; border-bottom: 1px solid #d5dde6; padding-bottom: 3px; }
    table { width: 100%; border-collapse: collapse; }
    .head td { vertical-align: top; }
    .muted { color: #5b6a7a; }
    .right { text-align: right; }
    .meta td { padding: 2px 0; }
    .meta td:first-child { color: #5b6a7a; width: 38%; }
    .items th { background: #174A83; color: #fff; text-align: left; padding: 6px 5px; font-size: 9px; }
    .items td { padding: 6px 5px; border-bottom: 1px solid #e3e9ef; vertical-align: top; }
    .items .num { text-align: right; white-space: nowrap; }
    .totals { width: 46%; margin-left: 54%; margin-top: 10px; }
    .totals td { padding: 3px 5px; }
    .totals .grand td { font-size: 12px; font-weight: bold; border-top: 2px solid #174A83; padding-top: 6px; }
    .box { border: 1px solid #d5dde6; padding: 8px 10px; background: #f7f9fb; white-space: pre-line; }
    .foot { position: fixed; bottom: -22px; left: 0; right: 0; font-size: 8px; color: #7b8896; text-align: center; }
    .badge { display: inline-block; border: 1px solid #174A83; color: #174A83; padding: 1px 6px; font-size: 8px; text-transform: uppercase; }
</style>
</head>
<body>
@php
    $money = fn ($v) => number_format((float) $v, 2);
    $date = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d M Y') : '-';
@endphp

<table class="head">
    <tr>
        <td style="width:58%">
            <h1>{{ $company['name'] }}</h1>
            @if($company['address'])<div class="muted">{{ $company['address'] }}</div>@endif
            <div class="muted">
                @if($company['phone']){{ $company['phone'] }}@endif
                @if($company['phone'] && $company['email']) &middot; @endif
                @if($company['email']){{ $company['email'] }}@endif
            </div>
        </td>
        <td class="right">
            <div style="font-size:16px;font-weight:bold;">QUOTATION</div>
            <div style="font-size:12px;margin-top:2px;">{{ $q->quotation_number }}</div>
            <div class="muted">Version {{ $q->version }}</div>
        </td>
    </tr>
</table>

<table style="margin-top:14px">
    <tr>
        <td style="width:50%;vertical-align:top;padding-right:14px">
            <h2>Prepared for</h2>
            <strong>{{ $q->customer_name_snapshot }}</strong><br>
            @if($q->company_name_snapshot){{ $q->company_name_snapshot }}<br>@endif
            @if($q->billing_address_snapshot)<span style="white-space:pre-line">{{ $q->billing_address_snapshot }}</span><br>@endif
            @if($q->customer_email_snapshot){{ $q->customer_email_snapshot }}<br>@endif
            @if($q->customer_phone_snapshot){{ $q->customer_phone_snapshot }}@endif
        </td>
        <td style="width:50%;vertical-align:top">
            <h2>Details</h2>
            <table class="meta">
                <tr><td>Issue date</td><td>{{ $date($q->issue_date) }}</td></tr>
                <tr><td>Valid until</td><td>{{ $date($q->valid_until) }}</td></tr>
                <tr><td>Currency</td><td>{{ $q->currency_code }}</td></tr>
                <tr><td>Enquiry reference</td><td>{{ $q->lead?->public_reference }}</td></tr>
                @if($q->creator)<tr><td>Prepared by</td><td>{{ $q->creator->name }}</td></tr>@endif
                @if($q->approver)<tr><td>Approved by</td><td>{{ $q->approver->name }}@if($q->approved_at), {{ $q->approved_at->timezone($tz)->format('d M Y') }}@endif</td></tr>@endif
            </table>
        </td>
    </tr>
</table>

<h2>Equipment &amp; pricing</h2>
<table class="items">
    <thead>
    <tr>
        <th style="width:4%">#</th>
        <th>Description</th>
        <th class="num" style="width:7%">Qty</th>
        <th class="num" style="width:13%">Unit price</th>
        <th class="num" style="width:11%">Discount</th>
        <th class="num" style="width:7%">Tax %</th>
        <th class="num" style="width:14%">Line total</th>
    </tr>
    </thead>
    <tbody>
    @foreach($q->items as $item)
        <tr>
            <td>{{ $loop->iteration }}</td>
            <td>
                <strong>{{ $item->product_name_snapshot }}</strong>
                @if($item->product_code_snapshot)<span class="muted"> &middot; {{ $item->product_code_snapshot }}</span>@endif
                @if($item->description_snapshot)<div class="muted">{{ $item->description_snapshot }}</div>@endif
            </td>
            <td class="num">{{ $item->quantity }}</td>
            <td class="num">{{ $money($item->unit_price) }}</td>
            <td class="num">{{ $money($item->discount_amount) }}</td>
            <td class="num">{{ rtrim(rtrim(number_format((float) $item->tax_rate, 2), '0'), '.') }}</td>
            <td class="num">{{ $money($item->line_total) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table class="totals">
    <tr><td>Subtotal</td><td class="right">{{ $q->currency_code }} {{ $money($q->subtotal) }}</td></tr>
    <tr><td>Discount</td><td class="right">- {{ $q->currency_code }} {{ $money($q->discount_total) }}</td></tr>
    <tr><td>Tax</td><td class="right">{{ $q->currency_code }} {{ $money($q->tax_total) }}</td></tr>
    @if((float) $q->freight_amount > 0)
        <tr><td>Freight / other charges</td><td class="right">{{ $q->currency_code }} {{ $money($q->freight_amount) }}</td></tr>
    @endif
    <tr class="grand"><td>Grand total</td><td class="right">{{ $q->currency_code }} {{ $money($q->grand_total) }}</td></tr>
</table>

@foreach([['Payment terms', $q->payment_terms], ['Delivery terms', $q->delivery_terms], ['Warranty', $q->warranty_terms], ['Terms &amp; conditions', $q->terms_and_conditions], ['Notes', $q->notes]] as [$label, $text])
    @if($text)
        <h2>{!! $label !!}</h2>
        <div class="box">{{ $text }}</div>
    @endif
@endforeach

<h2>Acceptance</h2>
<div>
    This offer is valid until <strong>{{ $date($q->valid_until) }}</strong>. To accept, send us your signed copy or purchase order
    quoting <strong>{{ $q->quotation_number }}</strong>, or reply to the e-mail this document came with. To ask for changes, contact
    our sales team and we will issue a revised version; earlier versions are then withdrawn.
</div>

<div class="foot">{{ $company['name'] }} &middot; {{ $q->quotation_number }} &middot; This document was generated from our quotation records.</div>
</body>
</html>
