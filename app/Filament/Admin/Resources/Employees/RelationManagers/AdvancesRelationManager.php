<?php

namespace App\Filament\Admin\Resources\Employees\RelationManagers;

use App\Domain\CRM\Models\Contact;
use App\Domain\Payroll\Enums\AdvanceStatus;
use App\Domain\Payroll\Models\EmployeeAdvance;
use App\Filament\Admin\Resources\EmployeeAdvances\EmployeeAdvanceResource;
use App\Filament\Admin\Support\VoucherResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/** The employee's advances (all years: an open one is still owed) with the voucher that paid each one. */
class AdvancesRelationManager extends RelationManager
{
    protected static string $relationship = 'advances';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Advances');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Contact && (bool) auth()->user()?->can('advances.view');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $live = fn (QueryBuilder $query) => $query->whereNull('voided_at');

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('payment'))
            ->defaultSort('advance_date', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('Number'))->weight('bold')
                    ->description(fn (EmployeeAdvance $r) => $r->voided_at ? __('Voided') : null),
                TextColumn::make('advance_date')->label(__('Date'))->date()->sortable(),
                TextColumn::make('amount')->label(__('Amount'))->numeric(2)->summarize(Sum::make()->label(__('Total'))->numeric(2)->query($live)),
                TextColumn::make('installment_amount')->label(__('Monthly installment'))->numeric(2)->placeholder('—'),
                TextColumn::make('remaining')->label(__('Remaining'))->numeric(2)
                    ->color(fn ($state) => (float) $state > 0 ? 'warning' : 'success')
                    ->summarize(Sum::make()->label(__('Remaining'))->numeric(2)->query($live)),
                TextColumn::make('status')->label(__('Status'))->badge(),
                TextColumn::make('payment.number')->label(__('Payment voucher'))->placeholder('—')
                    ->url(fn (EmployeeAdvance $r) => $r->payment ? VoucherResource::urlFor($r->payment) : null),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(AdvanceStatus::class),
            ])
            ->recordUrl(fn (EmployeeAdvance $record) => EmployeeAdvanceResource::getUrl('view', ['record' => $record]));
    }
}
