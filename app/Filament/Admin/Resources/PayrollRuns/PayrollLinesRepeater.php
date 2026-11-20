<?php

namespace App\Filament\Admin\Resources\PayrollRuns;

use App\Domain\Payroll\Models\PayrollLine;
use App\Domain\Payroll\Services\PayrollCalculator;
use App\Support\Decimal;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * The payroll sheet: one row per employee, every amount editable, the net recalculated as you type.
 * Rows carry `id` (an existing line) or `contact_id` (a new payroll) plus the display-only columns.
 */
final class PayrollLinesRepeater
{
    public static function make(): Repeater
    {
        $amount = fn (string $name) => TextInput::make($name)->numeric()->minValue(0)->default(0)->live(onBlur: true)
            ->afterStateUpdated(fn (Get $get, Set $set) => self::recalculate($get, $set));
        $absence = function (Get $get, Set $set) {
            $set('absence_deduction', (string) PayrollCalculator::absenceDeduction($get('base_salary'), $get('absence_days')));
            self::recalculate($get, $set);
        };

        return Repeater::make('lines')->label(__('Employees'))->columnSpanFull()
            ->addable(false)->reorderable(false)->deletable()->live()
            ->table([
                TableColumn::make(__('Employee'))->width('13%'),
                TableColumn::make(__('Open advances')),
                TableColumn::make(__('Salary')), TableColumn::make(__('Allowances')), TableColumn::make(__('Overtime')),
                TableColumn::make(__('Bonus')), TableColumn::make(__('Days absent')), TableColumn::make(__('Absence deduction')),
                TableColumn::make(__('Penalties')), TableColumn::make(__('Other deductions')), TableColumn::make(__('Advance deduction')),
                TableColumn::make(__('Net')), TableColumn::make(__('Note')),
            ])
            ->schema([
                Hidden::make('id'),
                Hidden::make('contact_id'),
                TextInput::make('employee')->disabled()->dehydrated(false),
                TextInput::make('open_advances')->disabled()->dehydrated(false)
                    ->extraInputAttributes(fn (Get $get) => (float) $get('open_advances') > 0 ? ['class' => 'text-warning-600 font-bold'] : []),
                $amount('base_salary')->afterStateUpdated($absence),
                $amount('allowances'),
                $amount('overtime'),
                $amount('bonus'),
                $amount('absence_days')->maxValue(31)->afterStateUpdated($absence),
                $amount('absence_deduction'),
                $amount('penalties'),
                $amount('other_deductions'),
                $amount('advance_deduction')->maxValue(fn (Get $get) => (float) $get('open_advances')),
                TextInput::make('net')->disabled()->dehydrated(false)->extraInputAttributes(['class' => 'font-bold']),
                TextInput::make('note')->maxLength(255),
            ]);
    }

    /** Totals under the sheet, updated with every change. */
    public static function totals(): Grid
    {
        $sum = fn (Get $get, callable $value) => number_format(collect($get('lines') ?? [])->sum(fn (array $line) => (float) $value($line)), 2);

        return Grid::make(4)->columnSpanFull()->schema([
            TextEntry::make('count_preview')->label(__('Employees'))->state(fn (Get $get) => count($get('lines') ?? [])),
            TextEntry::make('earned_preview')->label(__('Total salaries'))
                ->state(fn (Get $get) => $sum($get, fn (array $l) => (string) PayrollCalculator::earned($l))),
            TextEntry::make('advances_preview')->label(__('Advance deductions'))
                ->state(fn (Get $get) => $sum($get, fn (array $l) => $l['advance_deduction'] ?? 0)),
            TextEntry::make('net_preview')->label(__('Net to pay'))->weight('bold')->size('lg')
                ->state(fn (Get $get) => $sum($get, fn (array $l) => (string) PayrollCalculator::net($l))),
        ]);
    }

    /**
     * A repeater row for a line or a not-yet-saved line (array from GeneratePayroll::lineFor()).
     *
     * @param  array<string, mixed>|PayrollLine  $line
     * @return array<string, mixed>
     */
    public static function row(array|PayrollLine $line, string $employee, string $openAdvances): array
    {
        $values = $line instanceof PayrollLine ? $line->only(PayrollLine::EDITABLE) : $line;

        return [
            'id' => $line instanceof PayrollLine ? $line->id : null,
            'contact_id' => $line instanceof PayrollLine ? $line->contact_id : $line['contact_id'],
            'employee' => $employee,
            'open_advances' => Decimal::toDb($openAdvances, 2),
            ...collect(PayrollLine::EDITABLE)->mapWithKeys(fn ($c) => [$c => (float) ($values[$c] ?? 0)])->all(),
            'net' => Decimal::toDb(PayrollCalculator::net($values), 2),
            'note' => $line instanceof PayrollLine ? $line->note : null,
        ];
    }

    private static function recalculate(Get $get, Set $set): void
    {
        $line = [];
        foreach (PayrollLine::EDITABLE as $column) {
            $line[$column] = is_numeric($get($column)) ? $get($column) : 0;
        }
        $set('net', Decimal::toDb(PayrollCalculator::net($line), 2));
    }
}
