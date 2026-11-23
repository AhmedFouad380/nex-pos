@php
    $fmt = fn ($v) => number_format((float) $v, 2);
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Count report') }} {{ $count->number }}</title>
    <style>
        @page { size: A4; margin: 10mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Tahoma, Arial, sans-serif; font-size: 12px; color: #111; }
        .head { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #111; padding-bottom: 8px; margin-bottom: 10px; }
        .biz { font-size: 18px; font-weight: bold; }
        .muted { color: #555; }
        h1 { margin: 0; font-size: 20px; text-align: center; }
        h2 { font-size: 14px; margin: 16px 0 6px; }
        .cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; margin-bottom: 6px; }
        .card { border: 1px solid #bbb; border-radius: 4px; padding: 6px 8px; }
        .card b { display: block; font-size: 15px; margin-top: 2px; }
        .short { color: #b91c1c; } .surplus { color: #15803d; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f1f1f1; border: 1px solid #999; padding: 5px 4px; font-size: 11px; }
        td { border: 1px solid #bbb; padding: 4px; }
        .num { text-align: end; white-space: nowrap; font-variant-numeric: tabular-nums; }
        tfoot td { font-weight: bold; background: #f7f7f7; border-top: 2px solid #111; }
        .signs { display: flex; justify-content: space-between; margin-top: 36px; text-align: center; }
        .signs div { width: 28%; border-top: 1px solid #000; padding-top: 4px; }
        @media screen { body { background: #e5e5e5; padding: 20px; } .sheet { background: #fff; padding: 10mm; max-width: 210mm; margin: 0 auto; } }
    </style>
</head>
<body @if ($autoPrint) onload="window.print()" @endif>
<div class="sheet">
    <div class="head">
        <div>
            @if ($logo)<img src="{{ $logo }}" alt="" style="max-height: 50px; max-width: 150px; display: block; margin-bottom: 4px">@endif
            <div class="biz">{{ $settings->business_name }}</div>
            <div class="muted">{{ $count->location->name }}</div>
        </div>
        <div>
            <h1>{{ __('Count report') }}</h1>
            <div style="text-align: center; margin-top: 4px">{{ $count->scope->getLabel() }}</div>
        </div>
        <div style="text-align: end">
            <div>{{ __('Number') }}: <strong>{{ $count->number }}</strong></div>
            <div class="muted">{{ __('Status') }}: {{ $count->status->getLabel() }}</div>
            <div class="muted">{{ __('Started at') }}: {{ $count->started_at?->format('Y-m-d H:i') }}</div>
            @if ($count->posted_at)<div class="muted">{{ __('Posted at') }}: {{ $count->posted_at->format('Y-m-d H:i') }}</div>@endif
        </div>
    </div>

    <div class="cards">
        <div class="card">{{ __('Counted') }}<b>{{ $summary['counted'] }} / {{ $summary['lines'] }}</b></div>
        <div class="card short">{{ __('Shortages') }}<b>{{ $summary['shortage_lines'] }} · {{ $fmt($summary['shortage_qty']) }}</b>@if ($seeCost){{ $fmt($summary['shortage_value']) }}@endif</div>
        <div class="card surplus">{{ __('Surpluses') }}<b>{{ $summary['surplus_lines'] }} · {{ $fmt($summary['surplus_qty']) }}</b>@if ($seeCost){{ $fmt($summary['surplus_value']) }}@endif</div>
        <div class="card">{{ __('Net difference') }}<b>@if ($seeCost){{ $fmt($summary['surplus_value'] - $summary['shortage_value']) }}@else — @endif</b></div>
    </div>
    @if (! $count->posted_at)
        <div class="muted">{{ __('Not posted yet: the differences are against the system quantity when the count started.') }}</div>
    @endif

    @foreach ([['title' => __('Shortages'), 'rows' => $shortages, 'class' => 'short'], ['title' => __('Surpluses'), 'rows' => $surpluses, 'class' => 'surplus']] as $part)
        <h2 class="{{ $part['class'] }}">{{ $part['title'] }} ({{ $part['rows']->count() }})</h2>
        @if ($part['rows']->isEmpty())
            <div class="muted">—</div>
        @else
            <table>
                <thead>
                <tr>
                    <th>#</th>
                    <th>{{ __('Product') }}</th>
                    <th>{{ __('Code') }}</th>
                    <th>{{ __('System quantity') }}</th>
                    <th>{{ __('Counted quantity') }}</th>
                    <th>{{ __('Difference') }}</th>
                    @if ($seeCost)<th>{{ __('Unit cost') }}</th><th>{{ __('Difference value') }}</th>@endif
                </tr>
                </thead>
                <tbody>
                @foreach ($part['rows'] as $i => $item)
                    <tr>
                        <td class="num">{{ $i + 1 }}</td>
                        <td>{{ $item->variation->fullName() }}</td>
                        <td>{{ $item->variation->sku }}</td>
                        <td class="num">{{ $fmt($item->expected_qty) }}</td>
                        <td class="num">{{ $fmt($item->counted_qty) }}</td>
                        <td class="num {{ $part['class'] }}">{{ $fmt($item->difference) }}</td>
                        @if ($seeCost)
                            <td class="num">{{ $fmt($item->unit_cost) }}</td>
                            <td class="num {{ $part['class'] }}">{{ $fmt((float) $item->difference * (float) $item->unit_cost) }}</td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
                <tfoot>
                <tr>
                    <td colspan="5">{{ __('Total') }}</td>
                    <td class="num">{{ $fmt($part['rows']->sum(fn ($item) => (float) $item->difference)) }}</td>
                    @if ($seeCost)
                        <td></td>
                        <td class="num">{{ $fmt($part['rows']->sum(fn ($item) => (float) $item->difference * (float) $item->unit_cost)) }}</td>
                    @endif
                </tr>
                </tfoot>
            </table>
        @endif
    @endforeach

    <div class="signs">
        <div>{{ __('Counted by') }}</div>
        <div>{{ __('Store keeper') }}</div>
        <div>{{ __('Approved by') }}</div>
    </div>
</div>
</body>
</html>
