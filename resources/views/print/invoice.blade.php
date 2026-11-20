@php
    $fmt = fn ($v) => number_format((float) $v, $settings->currency_precision);
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
    $rtl = app()->getLocale() === 'ar';
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $layout->label($doc->headingKey) }} {{ $doc->number }}</title>
    <style>
        @page { size: {{ $layout->paper->value === 'a5' ? 'A5' : 'A4' }}; margin: 12mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Tahoma, Arial, sans-serif; font-size: {{ $layout->paper->value === 'a5' ? '11px' : '13px' }}; color: #111; }
        .sheet { max-width: {{ $layout->width() - 24 }}mm; margin: 0 auto; position: relative; }
        .head { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; border-bottom: 2px solid #111; padding-bottom: 10px; }
        .biz h1 { margin: 0 0 4px; font-size: 1.6em; }
        .muted { color: #555; }
        .logo { max-height: 70px; max-width: 180px; }
        .title { text-align: end; }
        .title h2 { margin: 0; font-size: 1.7em; letter-spacing: 0.02em; }
        .meta { display: flex; justify-content: space-between; gap: 1rem; margin: 14px 0; }
        .box { border: 1px solid #ccc; border-radius: 6px; padding: 8px 12px; min-width: 40%; }
        .box div { margin: 2px 0; }
        table.items { width: 100%; border-collapse: collapse; }
        table.items th { background: #f1f1f1; border-bottom: 2px solid #111; padding: 6px; text-align: start; }
        table.items td { border-bottom: 1px solid #ddd; padding: 6px; vertical-align: top; }
        .num { text-align: end; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .bottom { display: flex; justify-content: space-between; gap: 1.5rem; margin-top: 12px; align-items: flex-start; }
        table.totals { min-width: 45%; border-collapse: collapse; }
        table.totals td { padding: 4px 6px; }
        table.totals .grand td { font-size: 1.25em; font-weight: bold; border-top: 2px solid #111; }
        .words { margin-top: 10px; font-weight: bold; }
        .barcode { height: 40px; width: 220px; margin-top: 8px; }
        .footer { margin-top: 24px; border-top: 1px solid #ccc; padding-top: 8px; text-align: center; white-space: pre-line; }
        .void { position: absolute; top: 35%; inset-inline: 0; text-align: center; font-size: 80px; font-weight: bold; color: rgb(220 38 38 / 0.25); transform: rotate(-15deg); }
        @media screen { body { background: #e5e5e5; padding: 20px 0; } .sheet { background: #fff; padding: 12mm; box-shadow: 0 2px 12px rgb(0 0 0 / 0.15); } }
    </style>
</head>
<body @if ($autoPrint) onload="window.print()" @endif>
<div class="sheet">
    @if ($doc->voided)<div class="void">{{ __('Voided') }}</div>@endif

    <div class="head">
        <div class="biz">
            @if ($logo)<img src="{{ $logo }}" class="logo" alt="">@endif
            @if ($layout->show('show_business_name'))<h1>{{ $settings->business_name }}</h1>@endif
            @if ($layout->show('show_location_name'))<div>{{ $doc->location->name }}</div>@endif
            <div class="muted">{{ collect([$doc->location->address ?: $settings->address, $doc->location->phone ?: $settings->phone])->filter()->implode(' — ') }}</div>
            @if ($layout->show('show_tax_number'))
                @foreach ([[$settings->tax_label_1, $settings->tax_number_1], [$settings->tax_label_2, $settings->tax_number_2]] as [$label, $number])
                    @if ($number)<div class="muted">{{ $label }}: {{ $number }}</div>@endif
                @endforeach
            @endif
            @if ($text = $layout->text('header_text'))<div style="white-space: pre-line; margin-top: 4px">{{ $text }}</div>@endif
        </div>
        <div class="title">
            <h2>{{ $layout->label($doc->headingKey) }}</h2>
            @if ($layout->show('show_barcode'))<div class="barcode">{!! \App\Domain\Printing\Services\Code128::svg($doc->number) !!}</div>@endif
        </div>
    </div>

    <div class="meta">
        @if ($layout->show('show_customer'))
            <div class="box">
                <div><strong>{{ $layout->label('customer') }}:</strong> {{ $doc->customerName }}</div>
                @if ($layout->show('show_mobile_number') && $doc->customerMobile)<div>{{ $doc->customerMobile }}</div>@endif
            </div>
        @endif
        <div class="box">
            <div><strong>{{ $layout->label('invoice_no') }}:</strong> {{ $doc->number }}</div>
            <div><strong>{{ $layout->label('date') }}:</strong> {{ $doc->date->format($settings->date_format.' H:i') }}</div>
            @if ($doc->cashier)<div><strong>{{ $layout->label('cashier') }}:</strong> {{ $doc->cashier }}</div>@endif
        </div>
    </div>

    <table class="items">
        <thead><tr>
            <th style="width: 2.5em">#</th>
            @if ($layout->show('show_sku'))<th>{{ __('SKU') }}</th>@endif
            <th>{{ $layout->label('product') }}</th>
            <th class="num">{{ $layout->label('qty') }}</th>
            <th class="num">{{ $layout->label('unit_price') }}</th>
            <th class="num">{{ $layout->label('total') }}</th>
        </tr></thead>
        <tbody>
        @foreach ($doc->lines as $i => $line)
            <tr>
                <td>{{ $i + 1 }}</td>
                @if ($layout->show('show_sku'))<td>{{ $line['sku'] }}</td>@endif
                <td>{{ $line['name'] }}</td>
                <td class="num">{{ $qty($line['qty']) }} {{ $line['unit'] }}</td>
                <td class="num">{{ $fmt($line['price']) }}</td>
                <td class="num">{{ $fmt($line['total']) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <div class="bottom">
        <div>
            @if ($layout->show('show_payments') && $doc->payments)
                <strong>{{ __('printing.labels.payments') }}</strong>
                <table class="totals">
                    @foreach ($doc->payments as [$method, $amount])<tr><td>{{ $method }}</td><td class="num">{{ $fmt($amount) }}</td></tr>@endforeach
                </table>
            @endif
            @if ($doc->note)<div class="muted" style="margin-top: 8px">{{ $doc->note }}</div>@endif
        </div>
        <table class="totals">
            @foreach ($doc->totals as [$key, $amount])
                <tr><td>{{ $layout->label($key) }}</td><td class="num">{{ $fmt($amount) }}</td></tr>
            @endforeach
            <tr class="grand"><td>{{ $layout->label('total') }}</td><td class="num">{{ $fmt($doc->total) }} {{ $symbol }}</td></tr>
            @if ((float) $doc->due > 0)<tr><td>{{ $layout->label('due') }}</td><td class="num">{{ $fmt($doc->due) }}</td></tr>@endif
            @if ($layout->show('show_previous_balance') && $doc->previousBalance !== null)
                <tr><td>{{ $layout->label('previous_balance') }}</td><td class="num">{{ $fmt($doc->previousBalance) }}</td></tr>
            @endif
        </table>
    </div>

    @if ($layout->show('show_amount_in_words'))<div class="words">{{ $words }}</div>@endif

    @if ($text = $layout->text('footer_text'))<div class="footer">{{ $text }}</div>@endif
</div>
</body>
</html>
