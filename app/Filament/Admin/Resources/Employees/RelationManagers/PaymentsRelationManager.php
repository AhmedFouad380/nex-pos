<?php

namespace App\Filament\Admin\Resources\Employees\RelationManagers;

use App\Domain\CRM\Models\Contact;
use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Finance\Models\Payment;
use App\Filament\Admin\Support\VoucherResource;
use App\Support\FiscalYear;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;

/** Every voucher of the employee: what we paid them (advances, salaries, custody…) and what they paid back — in the selected year. */
class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Vouchers');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Contact && (bool) auth()->user()?->can('payments.view');
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $sum = fn (PaymentDirection $direction) => Sum::make()->label($direction === PaymentDirection::Out ? __('Paid to the employee') : __('Received from the employee'))->numeric(2)
            ->query(fn (QueryBuilder $query) => $query->whereNull('voided_at')->where('direction', $direction->value));

        return $table
            ->modifyQueryUsing(fn (Builder $query) => FiscalYear::scope($query, 'paid_at')->with(['paymentMethod', 'creator']))
            ->defaultSort('paid_at', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('Number'))->searchable()->weight('bold')
                    ->description(fn (Payment $r) => $r->voided_at ? __('Voided') : null),
                TextColumn::make('paid_at')->label(__('Date'))->dateTime()->sortable(),
                TextColumn::make('direction')->label(__('Type'))->badge()
                    ->formatStateUsing(fn (PaymentDirection $state) => $state === PaymentDirection::Out ? __('Payment voucher') : __('Receipt voucher')),
                TextColumn::make('paymentMethod.name')->label(__('Payment method')),
                TextColumn::make('amount')->label(__('Amount'))->numeric(2)->sortable()->weight('bold')
                    ->color(fn (Payment $r) => $r->voided_at ? 'gray' : $r->direction->getColor())
                    ->summarize([$sum(PaymentDirection::Out), $sum(PaymentDirection::In)]),
                TextColumn::make('note')->label(__('Note'))->placeholder('—')->limit(40)->wrap(),
                TextColumn::make('reference')->label(__('Reference'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('creator.name')->label(__('By'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('direction')->label(__('Type'))->options([
                    PaymentDirection::Out->value => __('Payment voucher'), PaymentDirection::In->value => __('Receipt voucher'),
                ]),
                Filter::make('paid_at')->columns(2)->schema([
                    DatePicker::make('from')->label(__('From')),
                    DatePicker::make('until')->label(__('Until')),
                ])->query(fn (Builder $query, array $data) => $query
                    ->when($data['from'] ?? null, fn (Builder $query, $date) => $query->whereDate('paid_at', '>=', $date))
                    ->when($data['until'] ?? null, fn (Builder $query, $date) => $query->whereDate('paid_at', '<=', $date))),
                Filter::make('hide_voided')->label(__('Hide voided'))->default()->query(fn (Builder $query) => $query->whereNull('voided_at')),
            ])
            ->recordUrl(fn (Payment $record) => VoucherResource::urlFor($record))
            ->recordActions([
                Action::make('print')->label(__('Print'))->icon('heroicon-o-printer')->color('gray')
                    ->url(fn (Payment $record) => route('vouchers.print', $record), shouldOpenInNewTab: true),
            ]);
    }
}
