<?php

namespace App\Http\Controllers;

use App\Domain\Inventory\Models\StockCount;
use App\Filament\Admin\Resources\StockCounts\StockCountResource;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** The count report (A4): totals, then the shortages and the surpluses item by item. */
class StockCountPrintController
{
    public function __invoke(Request $request, StockCount $count): View
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->can('stock_counts.manage'), 403);
        abort_unless(StockCountResource::getEloquentQuery()->whereKey($count->id)->exists(), 404); // same branch limits as the screens

        $count->load(['location', 'creator', 'stockAdjustment', 'surplusAdjustment']);
        $lines = $count->items()->whereNotNull('counted_qty')->where('difference', '!=', 0)
            ->with(['variation.product.unit', 'variation.product.category'])->get()
            ->sortBy(fn ($item) => $item->variation->fullName())->values();
        $settings = app(GeneralSettings::class);

        return view('inventory.stock-count', [
            'count' => $count,
            'summary' => $count->summary(),
            'shortages' => $lines->filter(fn ($item) => (float) $item->difference < 0)->values(),
            'surpluses' => $lines->filter(fn ($item) => (float) $item->difference > 0)->values(),
            'settings' => $settings,
            'logo' => $settings->logoDataUri(),
            'seeCost' => $user->can('products.view_purchase_price'),
            'autoPrint' => $request->boolean('print', true),
        ]);
    }
}
