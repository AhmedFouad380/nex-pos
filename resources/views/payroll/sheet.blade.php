@php
    $fmt = fn ($v) => number_format((float) $v, $settings->currency_precision);
    $sum = fn (string $column) => $lines->sum(fn ($l) => (float) $l->{$column});
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Payroll sheet') }} {{ $run->number }}</title>
    <style>
        @page { size: A4 landscape; margin: 10mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Tahoma, Arial, sans-serif; font-size: 12px; color: #111; }
        .sheet { position: relative; }
        .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #111; padding-bottom: 8px; margin-bottom: 10px; }
        .biz { font-size: 18px; font-weight: bold; }
        .muted { color: #555; }
        h1 { margin: 0; font-size: 20px; text-align: center; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f1f1f1; border: 1px solid #999; padding: 5px 4px; font-size: 11px; }
        td { border: 1px solid #bbb; padding: 5px 4px; }
        .num { text-align: end; white-space: nowrap; font-variant-numeric: tabular-nums; }
        tfoot td { font-weight: bold; background: #f7f7f7; border-top: 2px solid #111; }
        .sign { width: 90px; }
        .signs { display: flex; justify-content: space-between; margin-top: 36px; text-align: center; }
        .signs div { width: 22%; border-top: 1px solid #000; padding-top: 4px; }
        .void { position: absolute; top: 30%; inset-inline: 0; text-align: center; font-size: 80px; font-weight: bold; color: rgb(220 38 38 / 0.25); transform: rotate(-12deg); }
        @media screen { body { background: #e5e5e5; padding: 20px; } .sheet { background: #fff; padding: 10mm; max-width: 297mm; margin: 0 auto; } }
    </style>
</head>
<body @if ($autoPrint) onload="window.print()" @endif>
<div class="sheet">
    @if ($run->voided_at)<div class="void">{{ __('Voided') }}</div>@endif
    <div class="head">
        <div>
            @if ($logo)<img src="{{ $logo }}" alt="" style="max-height: 50px; max-width: 150px; display: block; margin-bottom: 4px">@endif
            <div class="biz">{{ $settings->business_name }}</div>
            <div class="muted">{{ $run->location?->name ?? __('All branches') }}</div>
        </div>
        <div>
            <h1>{{ __('Payroll sheet') }}</h1>
            <div style="text-align: center; margin-top: 4px">{{ $run->periodLabel() }}</div>
        </div>
        <div style="text-align: end">
            <div>{{ __('Number') }}: <strong>{{ $run->number }}</strong></div>
            <div class="muted">{{ __('Status') }}: {{ $run->status->getLabel() }}</div>
            <div class="muted">{{ __('Entry date') }}: {{ $run->posting_date->format('Y-m-d') }}</div>
        </div>
    </div>

    <table>
        <thead>
        <tr>
            <th>#</th>
            <th>{{ __('Employee') }}</th>
            <th>{{ __('Job title') }}</th>
            <th>{{ __('Salary') }}</th>
            <th>{{ __('Allowances') }}</th>
            <th>{{ __('Overtime') }}</th>
            <th>{{ __('Bonus') }}</th>
            <th>{{ __('Days absent') }}</th>
            <th>{{ __('Absence deduction') }}</th>
            <th>{{ __('Penalties') }}</th>
            <th>{{ __('Other deductions') }}</th>
            <th>{{ __('Earned') }}</th>
            <th>{{ __('Advance') }}</th>
            <th>{{ __('Net') }}</th>
            <th class="sign">{{ __('Signature') }}</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($lines as $i => $line)
            <tr>
                <td>{{ $i + 1 }}</td>
                <td>{{ $line->contact->name }}@if ($line->note)<div class="muted">{{ $line->note }}</div>@endif</td>
                <td>{{ $line->contact->employeeProfile?->job_title }}</td>
                <td class="num">{{ $fmt($line->base_salary) }}</td>
                <td class="num">{{ $fmt($line->allowances) }}</td>
                <td class="num">{{ $fmt($line->overtime) }}</td>
                <td class="num">{{ $fmt($line->bonus) }}</td>
                <td class="num">{{ (float) $line->absence_days ?: '' }}</td>
                <td class="num">{{ $fmt($line->absence_deduction) }}</td>
                <td class="num">{{ $fmt($line->penalties) }}</td>
                <td class="num">{{ $fmt($line->other_deductions) }}</td>
                <td class="num">{{ $fmt($line->earned) }}</td>
                <td class="num">{{ $fmt($line->advance_deduction) }}</td>
                <td class="num"><strong>{{ $fmt($line->net) }}</strong></td>
                <td></td>
            </tr>
        @endforeach
        </tbody>
        <tfoot>
        <tr>
            <td colspan="3">{{ __('Total') }} ({{ $lines->count() }})</td>
            @foreach (['base_salary', 'allowances', 'overtime', 'bonus'] as $c)<td class="num">{{ $fmt($sum($c)) }}</td>@endforeach
            <td></td>
            @foreach (['absence_deduction', 'penalties', 'other_deductions', 'earned', 'advance_deduction', 'net'] as $c)<td class="num">{{ $fmt($sum($c)) }}</td>@endforeach
            <td></td>
        </tr>
        </tfoot>
    </table>

    <div class="signs">
        <div>{{ __('Prepared by') }}<br><span class="muted">{{ $run->creator?->name }}</span></div>
        <div>{{ __('Accountant') }}</div>
        <div>{{ __('Approved by') }}<br><span class="muted">{{ $run->approver?->name }}</span></div>
        <div>{{ __('Manager') }}</div>
    </div>
</div>
</body>
</html>
