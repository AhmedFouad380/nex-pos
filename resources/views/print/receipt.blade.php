@php
    $fmt = fn ($v) => number_format((float) $v, $settings->currency_precision);
    $qty = fn ($v) => rtrim(rtrim(number_format((float) $v, 3), '0'), '.');
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ $doc->number }}</title>
    <style>
        @page { size: {{ $layout->width() }}mm auto; margin: 0; }
        * { box-sizing: border-box; }
        body { width: {{ $layout->width() }}mm; margin: 0; padding: 3mm; font-family: Tahoma, Arial, sans-serif; font-size: {{ $layout->width() < 70 ? '11px' : '12px' }}; color: #000; }
        .center { text-align: center; }
        .muted { font-size: 0.9em; }
        h1 { font-size: 1.35em; margin: 0 0 2px; }
        .logo { max-width: 60%; max-height: 22mm; }
        hr { border: 0; border-top: 1px dashed #000; margin: 5px 0; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 2px 0; vertical-align: top; }
        th { text-align: start; border-bottom: 1px solid #000; font-size: 0.9em; }
        .num { text-align: end; white-space: nowrap; }
        .total td { font-weight: bold; font-size: 1.2em; border-top: 1px solid #000; padding-top: 4px; }
        .void { border: 2px solid #000; padding: 4px; font-weight: bold; text-align: center; margin: 5px 0; }
        .barcode { height: 12mm; margin: 4px auto 0; width: 85%; }
        .pre { white-space: pre-line; }
    </style>
</head>
<body @if ($autoPrint) onload="window.print()" @endif>
    <div class="center">
        @if ($logo)<img src="{{ $logo }}" class="logo" alt=""><br>@endif
        @if ($layout->show('show_business_name'))<h1>{{ $settings->business_name }}</h1>@endif
        @if ($layout->show('show_location_name'))<div class="muted">{{ $doc->location->name }}@if ($doc->location->phone) — {{ $doc->location->phone }}@endif</div>@endif
        @if ($layout->show('show_tax_number') && $settings->tax_number_1)<div class="muted">{{ $settings->tax_label_1 }}: {{ $settings->tax_number_1 }}</div>@endif
        @if ($text = $layout->text('header_text'))<div class="muted pre">{{ $text }}</div>@endif
    </div>
    <hr>
    <div>{{ $layout->label($doc->headingKey) }}: <strong>{{ $doc->number }}</strong></div>
    <div>{{ $layout->label('date') }}: {{ $doc->date->format($settings->date_format.' H:i') }}</div>
    @if ($layout->show('show_customer') && ! $doc->walkIn)<div>{{ $layout->label('customer') }}: {{ $doc->customerName }}@if ($layout->show('show_mobile_number') && $doc->customerMobile) — {{ $doc->customerMobile }}@endif</div>@endif
    @if ($doc->cashier)<div>{{ $layout->label('cashier') }}: {{ $doc->cashier }}</div>@endif
    @if ($doc->voided)<div class="void">{{ __('Voided') }}</div>@endif
    <hr>
    <table>
        <thead><tr><th>{{ $layout->label('product') }}</th><th class="num">{{ $layout->label('qty') }}</th><th class="num">{{ $layout->label('unit_price') }}</th><th class="num">{{ $layout->label('total') }}</th></tr></thead>
        <tbody>
        @foreach ($doc->lines as $line)
            <tr>
                <td>{{ $line['name'] }}@if ($line['unit']) ({{ $line['unit'] }})@endif @if ($layout->show('show_sku'))<br><span class="muted">{{ $line['sku'] }}</span>@endif</td>
                <td class="num">{{ $qty($line['qty']) }}</td>
                <td class="num">{{ $fmt($line['price']) }}</td>
                <td class="num">{{ $fmt($line['total']) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <hr>
    <table>
        @foreach ($doc->totals as [$key, $amount])<tr><td>{{ $layout->label($key) }}</td><td class="num">{{ $fmt($amount) }}</td></tr>@endforeach
        <tr class="total"><td>{{ $layout->label('total') }}</td><td class="num">{{ $fmt($doc->total) }}</td></tr>
        @if ($layout->show('show_payments'))
            @foreach ($doc->payments as [$method, $amount])<tr><td>{{ $method }}</td><td class="num">{{ $fmt($amount) }}</td></tr>@endforeach
        @endif
        @if ((float) $doc->due > 0)<tr><td><strong>{{ $layout->label('due') }}</strong></td><td class="num"><strong>{{ $fmt($doc->due) }}</strong></td></tr>@endif
        @if ($layout->show('show_previous_balance') && $doc->previousBalance !== null)<tr><td>{{ $layout->label('previous_balance') }}</td><td class="num">{{ $fmt($doc->previousBalance) }}</td></tr>@endif
    </table>
    @if ($layout->show('show_amount_in_words'))<div class="muted" style="margin-top: 4px">{{ $words }}</div>@endif
    @if ($layout->show('show_barcode'))<div class="barcode">{!! \App\Domain\Printing\Services\Code128::svg($doc->number) !!}</div>@endif
    <hr>
    <div class="center muted pre">{{ $layout->text('footer_text') ?? __('Thank you for your visit') }}</div>
</body>
</html>
