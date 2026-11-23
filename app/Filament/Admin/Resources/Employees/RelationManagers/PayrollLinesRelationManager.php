<?php

namespace App\Filament\Admin\Resources\Employees\RelationManagers;

use App\Domain\CRM\Models\Contact;
use App\Domain\Payroll\Models\PayrollLine;
use App\Filament\Admin\Resources\PayrollRuns\PayrollRunResource;
use App\Support\FiscalYear;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** The employee's salary in each payroll of the selected year: earned, deductions, net and whether it was handed over. */
class PayrollLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'payrollLines';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Salaries');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Contact && (bool) auth()->user()?->can('payroll.view');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        [$from, $to] = FiscalYear::range();

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('run')
                ->whereHas('run', fn (Builder $run) => $run->whereBetween('period', [$from->toDateString(), $to->toDateString()])))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('run.period')->label(__('Month'))->formatStateUsing(fn (PayrollLine $record) => $record->run->periodLabel()),
                TextColumn::make('run.status')->label(__('Status'))->badge(),
                TextColumn::make('earned')->label(__('Earned'))->numeric(2)->summarize(Sum::make()->label(__('Total'))->numeric(2)),
                TextColumn::make('advance_deduction')->label(__('Advance deduction'))->numeric(2)->summarize(Sum::make()->label(__('Total'))->numeric(2)),
                TextColumn::make('net')->label(__('Net'))->numeric(2)->weight('bold')->summarize(Sum::make()->label(__('Total'))->numeric(2)),
                IconColumn::make('paid')->label(__('Paid'))->boolean()->state(fn (PayrollLine $record) => $record->isPaid()),
            ])
            ->recordUrl(fn (PayrollLine $record) => PayrollRunResource::getUrl('view', ['record' => $record->payroll_run_id]));
    }
}
