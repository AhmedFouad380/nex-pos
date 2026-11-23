<?php

namespace App\Filament\Admin\Resources\StockCounts\Pages;

use App\Domain\Inventory\Actions\CancelStockCount;
use App\Domain\Inventory\Actions\PostStockCount;
use App\Domain\Inventory\Models\StockCount;
use App\Filament\Actions\ActivityHistoryAction;
use App\Filament\Admin\Resources\StockCounts\StockCountResource;
use App\Filament\Admin\Resources\StockCounts\Widgets\StockCountSummary;
use App\Support\DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Number;

/** The count sheet: the summary, the lines to fill in (below), the report, posting and cancelling. */
class ViewStockCount extends ViewRecord
{
    protected static string $resource = StockCountResource::class;

    protected function getHeaderWidgets(): array
    {
        return [StockCountSummary::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('report')->label(__('Count report'))->icon('heroicon-o-printer')->color('gray')
                ->url(fn (StockCount $record) => route('stock-counts.print', $record), shouldOpenInNewTab: true),
            Action::make('post')->label(__('Post the count'))->icon('heroicon-o-check-badge')->color('success')
                ->visible(fn (StockCount $record) => $record->isCounting())
                ->requiresConfirmation()
                ->modalHeading(__('Post the count'))
                ->modalDescription(function (StockCount $record) {
                    $s = $record->summary();

                    return __('Counted :counted of :lines items. Shortages: :short_lines items (:short_value), surpluses: :surplus_lines items (:surplus_value). The counted quantities become the stock of the branch, compared with what it has right now.', [
                        'counted' => $s['counted'], 'lines' => $s['lines'],
                        'short_lines' => $s['shortage_lines'], 'short_value' => Number::format($s['shortage_value'], 2),
                        'surplus_lines' => $s['surplus_lines'], 'surplus_value' => Number::format($s['surplus_value'], 2),
                    ]);
                })
                ->schema([
                    Toggle::make('uncounted_as_zero')->label(__('Items not counted are not in the store (set them to zero)'))
                        ->helperText(__('Off: items not counted keep their stock.')),
                ])
                ->action(function (StockCount $record, array $data) {
                    try {
                        app(PostStockCount::class)->handle($record, (bool) ($data['uncounted_as_zero'] ?? false));
                        Notification::make()->success()->title(__('The count is posted: the stock now matches the shelves.'))->send();
                    } catch (DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->persistent()->send();
                    }
                    $this->redirect(StockCountResource::getUrl('view', ['record' => $record]));
                }),
            Action::make('cancel')->label(__('Cancel the count'))->icon('heroicon-o-x-circle')->color('danger')
                ->visible(fn (StockCount $record) => $record->isCounting())
                ->requiresConfirmation()
                ->modalDescription(__('The stock stays as it is; the counted figures are kept for reference.'))
                ->action(function (StockCount $record) {
                    app(CancelStockCount::class)->handle($record);
                    $this->redirect(StockCountResource::getUrl('view', ['record' => $record]));
                }),
            ActivityHistoryAction::make(),
        ];
    }
}
