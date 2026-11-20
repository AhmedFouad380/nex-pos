@php
    $p = $paper;
    $has = fn ($f) => in_array($f, $show, true);
    $price = fn ($v) => number_format((float) $v, $settings->currency_precision);
    $sheetWidth = $template->is_continuous ? $p['label_width'] : $p['page_width'];
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Barcode labels') }}</title>
    <style>
        @if ($template->is_continuous)
            @page { size: {{ $p['label_width'] }}mm {{ $p['label_height'] }}mm; margin: 0; }
        @else
            @page { size: A4; margin: {{ $p['margin_top'] }}mm {{ $p['margin_side'] }}mm; }
        @endif
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Tahoma, Arial, sans-serif; color: #000; }
        .sheet { display: grid; grid-template-columns: repeat({{ $template->is_continuous ? 1 : $p['columns'] }}, {{ $p['label_width'] }}mm);
            column-gap: {{ $template->is_continuous ? 0 : $p['gap_x'] }}mm; row-gap: {{ $template->is_continuous ? 0 : $p['gap_y'] }}mm; width: {{ $sheetWidth }}mm; }
        .label { width: {{ $p['label_width'] }}mm; height: {{ $p['label_height'] }}mm; overflow: hidden; padding: 1mm 1.5mm;
            display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: 0.3mm;
            break-inside: avoid; @if ($template->is_continuous) break-after: page; @endif }
        .biz { font-size: 2.2mm; font-weight: bold; }
        .name { font-size: 2.6mm; line-height: 1.15; max-height: 6mm; overflow: hidden; }
        .bars { width: 100%; height: {{ max(6, $p['label_height'] * 0.38) }}mm; }
        .code { font-size: 2.2mm; letter-spacing: 0.3mm; font-family: monospace; }
        .price { font-size: 3.2mm; font-weight: bold; }
        @media screen { body { background: #ddd; padding: 10px; } .label { background: #fff; outline: 1px dashed #aaa; } }
    </style>
</head>
<body @if ($autoPrint && $labels) onload="window.print()" @endif>
    @if (! $labels)
        <p>{{ __('No labels to print.') }}</p>
    @endif
    <div class="sheet">
        @foreach ($labels as $label)
            <div class="label">
                @if ($has('business_name'))<div class="biz">{{ $settings->business_name }}</div>@endif
                @if ($has('name'))<div class="name">{{ $label['name'] }}</div>@endif
                @if ($has('barcode'))<div class="bars">{!! \App\Domain\Printing\Services\Code128::svg($label['barcode']) !!}</div>@endif
                @if ($has('barcode') || $has('sku'))<div class="code">{{ $has('barcode') ? $label['barcode'] : $label['sku'] }}</div>@endif
                @if ($has('price'))<div class="price">{{ $price($label['price']) }} {{ $symbol }}</div>@endif
            </div>
        @endforeach
    </div>
</body>
</html>
