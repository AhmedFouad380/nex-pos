<?php

namespace App\Http\Controllers;

use App\Domain\Foundation\Models\Currency;
use App\Domain\Printing\Services\PrintableDocument;
use App\Domain\Printing\Services\PrintLayout;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleReturn;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Support\ArabicAmountInWords;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Printable sale invoice / quotation / return — A4, A5 or thermal, following the location's invoice layout.
 * `?receipt=1` uses the receipt (thermal) layout, `?layout=ID` forces one, `?print=0` shows it without printing.
 */
class DocumentPrintController
{
    public function sale(Request $request, Sale $sale): View
    {
        $this->authorize($request, $sale->location_id, $sale->created_by, 'sales.view');

        return $this->render($request, PrintableDocument::fromSale($sale));
    }

    /** The POS receipt: always the thermal layout of the location. */
    public function receipt(Request $request, Sale $sale): View
    {
        $request->merge(['receipt' => 1]);

        return $this->sale($request, $sale);
    }

    public function saleReturn(Request $request, SaleReturn $return): View
    {
        $this->authorize($request, $return->location_id, $return->created_by, 'sale_returns.view');

        return $this->render($request, PrintableDocument::fromReturn($return));
    }

    private function authorize(Request $request, int $locationId, ?int $creatorId, string $permission): void
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->accessibleLocations()->whereKey($locationId)->exists(), 403);
        abort_unless($user->can($permission) || $user->can('pos.reprint') || $creatorId === $user->id, 403);
    }

    private function render(Request $request, PrintableDocument $document): View
    {
        $layout = PrintLayout::resolve($document->location, $request->boolean('receipt'), $request->integer('layout') ?: null);
        $settings = app(GeneralSettings::class);

        return view($layout->isThermal() ? 'print.receipt' : 'print.invoice', [
            'doc' => $document,
            'layout' => $layout,
            'settings' => $settings,
            'logo' => $layout->show('show_logo') ? $settings->logoDataUri() : null,
            'symbol' => Currency::query()->where('code', $settings->currency_code)->value('symbol') ?? $settings->currency_code,
            'words' => ArabicAmountInWords::money($document->total, $settings->currency_code),
            'autoPrint' => $request->boolean('print', true),
        ]);
    }
}
