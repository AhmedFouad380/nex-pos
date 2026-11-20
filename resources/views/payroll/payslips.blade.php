@php
    $fmt = fn ($v) => number_format((float) $v, $settings->currency_precision);
    $row = fn ($label, $value) => (float) $value != 0 ? [$label, $fmt($value)] : null;
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Payslips') }} {{ $run->number }}</title>
    <style>
        @page { size: A4; margin: 8mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Tahoma, Arial, sans-serif; font-size: 13px; color: #111; }
        .slip { border: 2px solid #000; border-radius: 6px; padding: 10px 14px; height: 136mm; margin-bottom: 8mm; position: relative; page-break-inside: avoid; display: flex; flex-direction: column; }
        .slip:nth-of-type(2n) { page-break-after: always; }
        .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 1px solid #000; padding-bottom: 6px; }
        .biz { font-size: 16px; font-weight: bold; }
        .muted { color: #555; font-size: 12px; }
        h1 { margin: 0; font-size: 18px; text-align: center; }
        .who { display: flex; gap: 24px; margin: 8px 0; flex-wrap: wrap; }
        .cols { display: flex; gap: 14px; flex: 1; }
        .cols table { width: 50%; border-collapse: collapse; align-self: flex-start; }
        th { background: #f1f1f1; border-bottom: 1px solid #000; padding: 4px 6px; text-align: start; }
        td { padding: 3px 6px; border-bottom: 1px dotted #bbb; }
        .num { text-align: end; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .net { border: 2px solid #000; border-radius: 6px; padding: 6px 10px; display: flex; justify-content: space-between; font-size: 16px; font-weight: bold; margin-top: 8px; }
        .words { margin-top: 4px; font-size: 12px; }
        .signs { display: flex; justify-content: space-between; margin-top: 18px; text-align: center; }
        .signs div { width: 30%; border-top: 1px solid #000; padding-top: 4px; font-size: 12px; }
        @media screen { body { background: #e5e5e5; padding: 20px; } .slip { background: #fff; max-width: 194mm; margin-inline: auto; } }
    </style>
</head>
<body @if ($autoPrint) onload="window.print()" @endif>
@foreach ($lines as $line)
    @php
        $additions = array_filter([$row(__('Salary'), $line->base_salary), $row(__('Allowances'), $line->allowances), $row(__('Overtime'), $line->overtime), $row(__('Bonus'), $line->bonus)]);
        $deductions = array_filter([
            $row(__('Absence deduction').((float) $line->absence_days ? ' ('.__(':days days absent', ['days' => (float) $line->absence_days]).')' : ''), $line->absence_deduction),
            $row(__('Penalties'), $line->penalties), $row(__('Other deductions'), $line->other_deductions), $row(__('Advance'), $line->advance_deduction)]);
    @endphp
    <div class="slip">
        <div class="head">
            <div>
                @if ($logo)<img src="{{ $logo }}" alt="" style="max-height: 40px; max-width: 120px; display: block; margin-bottom: 2px">@endif
                <div class="biz">{{ $settings->business_name }}</div>
            </div>
            <div>
                <h1>{{ __('Payslip') }}</h1>
                <div style="text-align: center">{{ $run->periodLabel() }}</div>
            </div>
            <div style="text-align: end" class="muted">
                <div>{{ $run->number }}</div>
                @if ($line->payment)<div>{{ __('Payment voucher') }}: {{ $line->payment->number }}</div>@endif
            </div>
        </div>
        <div class="who">
            <div><strong>{{ __('Employee') }}:</strong> {{ $line->contact->name }}</div>
            <div><strong>{{ __('Code') }}:</strong> {{ $line->contact->code }}</div>
            @if ($line->contact->employeeProfile?->job_title)<div><strong>{{ __('Job title') }}:</strong> {{ $line->contact->employeeProfile->job_title }}</div>@endif
        </div>
        <div class="cols">
            <table>
                <thead><tr><th>{{ __('Entitlements') }}</th><th class="num">{{ __('Amount') }}</th></tr></thead>
                <tbody>@foreach ($additions as [$label, $value])<tr><td>{{ $label }}</td><td class="num">{{ $value }}</td></tr>@endforeach</tbody>
            </table>
            <table>
                <thead><tr><th>{{ __('Deductions') }}</th><th class="num">{{ __('Amount') }}</th></tr></thead>
                <tbody>@forelse ($deductions as [$label, $value])<tr><td>{{ $label }}</td><td class="num">{{ $value }}</td></tr>@empty<tr><td colspan="2" class="muted">—</td></tr>@endforelse</tbody>
            </table>
        </div>
        @if ($line->note)<div class="muted">{{ __('Note') }}: {{ $line->note }}</div>@endif
        <div class="net"><span>{{ __('Net salary') }}</span><span>{{ $fmt($line->net) }} {{ $symbol }}</span></div>
        <div class="words">{{ $words($line->net) }}</div>
        <div class="signs">
            <div>{{ __('Employee signature') }}</div>
            <div>{{ __('Accountant') }}</div>
            <div>{{ __('Manager') }}</div>
        </div>
    </div>
@endforeach
</body>
</html>
