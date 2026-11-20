@php
    $in = $payment->direction->value === 'in';
    $fmt = fn ($v) => number_format((float) $v, $settings->currency_precision);
    $party = $payment->contact?->name ?? $payment->counterAccount?->name ?? '—';
    $for = $payment->allocations->map(fn ($a) => __('statement.kinds.'.$a->allocatable_type).' '
        .(\App\Support\DocumentNumber::of($a->allocatable) ?? ''))->implode('، ');
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $payment->number }}</title>
    <style>
        @page { size: A5 landscape; margin: 10mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Tahoma, Arial, sans-serif; font-size: 14px; color: #000; }
        .sheet { border: 2px solid #000; border-radius: 6px; padding: 14px 18px; max-width: 190mm; margin: 0 auto; position: relative; }
        .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 1px solid #000; padding-bottom: 8px; }
        .biz { font-size: 18px; font-weight: bold; }
        .muted { font-size: 12px; color: #333; }
        .title { text-align: center; }
        .title h1 { margin: 0; font-size: 22px; border: 2px solid #000; border-radius: 6px; padding: 4px 18px; display: inline-block; }
        .amount-box { border: 2px solid #000; border-radius: 6px; padding: 6px 12px; font-size: 18px; font-weight: bold; white-space: nowrap; }
        .row { display: flex; gap: 8px; margin-top: 14px; align-items: baseline; }
        .row .label { white-space: nowrap; font-weight: bold; }
        .row .value { flex: 1; border-bottom: 1px dotted #000; padding: 0 4px 2px; min-height: 1.4em; }
        .signs { display: flex; justify-content: space-between; margin-top: 40px; text-align: center; }
        .signs div { width: 30%; border-top: 1px solid #000; padding-top: 4px; font-size: 13px; }
        .void { position: absolute; inset: 40% 0 auto; text-align: center; font-size: 48px; font-weight: bold; color: rgb(220 38 38 / 0.35); transform: rotate(-12deg); }
        @media screen { body { background: #eee; padding: 20px; } .sheet { background: #fff; } }
    </style>
</head>
<body @if ($autoPrint) onload="window.print()" @endif>
<div class="sheet">
    @if ($payment->voided_at)<div class="void">{{ __('Voided') }}</div>@endif
    <div class="head">
        <div>
            @if ($logo)<img src="{{ $logo }}" alt="" style="max-height: 50px; max-width: 150px; display: block; margin-bottom: 4px">@endif
            <div class="biz">{{ $settings->business_name }}</div>
            @if ($payment->location)<div class="muted">{{ $payment->location->name }}</div>@endif
            @if ($settings->tax_number_1)<div class="muted">{{ $settings->tax_label_1 }}: {{ $settings->tax_number_1 }}</div>@endif
        </div>
        <div class="title">
            <h1>{{ $in ? __('Receipt voucher') : __('Payment voucher') }}</h1>
            <div class="muted" style="margin-top: 4px">{{ __('Number') }}: <strong>{{ $payment->number }}</strong></div>
        </div>
        <div style="text-align: end">
            <div class="amount-box">{{ $fmt($payment->amount) }} {{ $symbol }}</div>
            <div class="muted" style="margin-top: 4px">{{ __('Date') }}: {{ $payment->paid_at->format('Y-m-d') }}</div>
        </div>
    </div>

    <div class="row"><span class="label">{{ $in ? __('Received from') : __('Paid to') }}:</span><span class="value">{{ $party }}</span></div>
    <div class="row"><span class="label">{{ __('The sum of') }}:</span><span class="value">{{ $words }}</span></div>
    <div class="row">
        <span class="label">{{ __('Payment method') }}:</span><span class="value">{{ $payment->paymentMethod->name }}
            @if ($payment->cheque_number) — {{ __('Cheque number') }} {{ $payment->cheque_number }} @if ($payment->cheque_date) ({{ $payment->cheque_date->format('Y-m-d') }}) @endif @endif
            @if ($payment->bank_name) — {{ $payment->bank_name }} @endif</span>
    </div>
    <div class="row"><span class="label">{{ __('For') }}:</span><span class="value">{{ collect([$for, $payment->note])->filter()->implode(' — ') ?: '—' }}</span></div>

    <div class="signs">
        <div>{{ $in ? __('Cashier') : __('Recipient') }}</div>
        <div>{{ __('Accountant') }}<br><span class="muted">{{ $payment->creator?->name }}</span></div>
        <div>{{ __('Manager') }}</div>
    </div>
</div>
</body>
</html>
