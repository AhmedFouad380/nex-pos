<?php

namespace App\Filament\Admin\Pages;

use App\Support\FiscalYear;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/** لوحة التحكم: the figures of a year (the current one by default), a month or a day — picked at the top. */
class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

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
                ->options(fn () => FiscalYear::years())->default(fn () => FiscalYear::selected())
                ->visible(fn (Get $get) => $get('period') !== 'day'),
            Select::make('month')->label(__('Month'))->selectablePlaceholder(false)
                ->options(collect(range(1, 12))->mapWithKeys(fn (int $m) => [$m => now()->setDay(1)->setMonth($m)->translatedFormat('F')])->all())
                ->default(now()->month)->visible(fn (Get $get) => $get('period') === 'month'),
            DatePicker::make('day')->label(__('Day'))->default(now()->toDateString())->maxDate(now())
                ->visible(fn (Get $get) => $get('period') === 'day'),
        ]);
    }
}
