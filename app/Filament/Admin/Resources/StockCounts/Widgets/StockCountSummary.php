<?php

namespace App\Filament\Admin\Resources\StockCounts\Widgets;

use App\Domain\Inventory\Models\StockCount;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;
use Livewire\Attributes\On;

/** Progress and result of a count; refreshes as lines are counted. */
class StockCountSummary extends StatsOverviewWidget
{
    public ?Model $record = null;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int
    {
        return 4;
    }

    #[On('stock-count-updated')]
    public function refreshSummary(): void {}

    protected function getStats(): array
    {
        if (! $this->record instanceof StockCount) {
            return [];
        }
        $s = $this->record->summary();
        $money = fn (float $v) => Number::format($v, 2);
        $qty = fn (float $v) => Number::format($v, 2);
        $net = $s['surplus_value'] - $s['shortage_value'];
        $seeValue = (bool) auth()->user()?->can('products.view_purchase_price');

        return [
            Stat::make(__('Counted'), $s['counted'].' / '.$s['lines'])
                ->description(__(':left items left', ['left' => $s['lines'] - $s['counted']]))
                ->color($s['counted'] === $s['lines'] ? 'success' : 'warning'),
            Stat::make(__('Shortages'), $s['shortage_lines'].' '.__('items'))
                ->description(__('Quantity').' '.$qty($s['shortage_qty']).($seeValue ? ' · '.$money($s['shortage_value']) : ''))->color('danger'),
            Stat::make(__('Surpluses'), $s['surplus_lines'].' '.__('items'))
                ->description(__('Quantity').' '.$qty($s['surplus_qty']).($seeValue ? ' · '.$money($s['surplus_value']) : ''))->color('success'),
            Stat::make(__('Net difference'), $seeValue ? $money($net) : '—')
                ->description($net < 0 ? __('Loss') : ($net > 0 ? __('Gain') : __('Settled')))
                ->color($net < 0 ? 'danger' : ($net > 0 ? 'success' : 'gray')),
        ];
    }
}
