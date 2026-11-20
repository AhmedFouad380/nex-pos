<?php

namespace App\Http\Controllers;

use App\Domain\Foundation\Models\Currency;
use App\Domain\Printing\Models\LabelTemplate;
use App\Domain\Printing\Services\LabelSheet;
use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Sheet / roll of barcode labels. Query: items[variationId]=count, template=id, show[]=name|price|sku|barcode|business_name. */
class LabelPrintController
{
    public function __invoke(Request $request): View
    {
        abort_unless($request->user()?->can('labels.print'), 403);

        $template = LabelTemplate::query()->find($request->integer('template'))
            ?? LabelTemplate::query()->orderByDesc('is_default')->firstOrFail();
        $show = array_intersect((array) $request->input('show', LabelSheet::FIELDS), LabelSheet::FIELDS);
        $settings = app(GeneralSettings::class);

        return view('print.labels', [
            'template' => $template,
            'paper' => LabelSheet::paper($template),
            'labels' => LabelSheet::labels(array_filter((array) $request->input('items', []), fn ($n) => (int) $n > 0)),
            'show' => array_values($show),
            'settings' => $settings,
            'symbol' => Currency::query()->where('code', $settings->currency_code)->value('symbol') ?? '',
            'autoPrint' => $request->boolean('print', true),
        ]);
    }
}
