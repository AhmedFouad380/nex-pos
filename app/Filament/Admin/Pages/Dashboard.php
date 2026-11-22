<?php

namespace App\Filament\Admin\Pages;

use App\Support\FiscalYear;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * لوحة التحكم: the figures of a year (the current one by default), a month or a day — picked at the top.
 * Its year is the year of the top-bar switcher: changing one changes the other, so the whole page shows one year.
 */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    public function mount(): void
    {
        // the filters remembered from an earlier visit keep their period, but the year is always the selected one
        $this->filters = [...$this->filters ?? [], 'year' => FiscalYear::selected()];
        $this->getFiltersForm()->fill($this->filters);
        session()->put($this->getFiltersSessionKey(), $this->filters);
    }

    public function getColumns(): int|array
    {
        return ['md' => 2, 'xl' => 3];
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->columns(['md' => 3, 'xl' => 4])->components([
            Select::make('period')->label(__('Period'))->selectablePlaceholder(false)->live()
                ->options(['year' => __('Year'), 'month' => __('Month'), 'day' => __('Day')])->default('year'),
            Select::make('year')->label(__('Year'))->selectablePlaceholder(false)
                ->options(fn () => FiscalYear::years())->default(fn () => FiscalYear::selected())->live()
                ->afterStateUpdated(function (?string $state): void {
                    if (is_numeric($state) && array_key_exists((int) $state, FiscalYear::years())) {
                        FiscalYear::select((int) $state);
                        $this->redirect(static::getUrl()); // reload: the top bar, the banner and every widget follow
                    }
                })
                ->visible(fn (Get $get) => $get('period') !== 'day'),
            Select::make('month')->label(__('Month'))->selectablePlaceholder(false)
                ->options(collect(range(1, 12))->mapWithKeys(fn (int $m) => [$m => now()->setDay(1)->setMonth($m)->translatedFormat('F')])->all())
                ->default(now()->month)->visible(fn (Get $get) => $get('period') === 'month'),
            DatePicker::make('day')->label(__('Day'))->default(now()->toDateString())->maxDate(now())
                ->visible(fn (Get $get) => $get('period') === 'day'),
        ]);
    }
}
