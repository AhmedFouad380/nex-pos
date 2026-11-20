<?php

namespace App\Filament\Admin\Resources\EmployeeAdvances\Pages;

use App\Domain\Finance\Enums\PaymentMethodType;
use App\Domain\Finance\Models\PaymentMethod;
use App\Domain\Payroll\Actions\RepayAdvance;
use App\Domain\Payroll\Actions\VoidAdvance;
use App\Domain\Payroll\Models\EmployeeAdvance;
use App\Filament\Actions\ActivityHistoryAction;
use App\Filament\Admin\Resources\EmployeeAdvances\EmployeeAdvanceResource;
use App\Filament\Support\DocumentActions;
use App\Filament\Support\DomainGuard;
use App\Support\Decimal;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ViewEmployeeAdvance extends ViewRecord
{
    protected static string $resource = EmployeeAdvanceResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['contact', 'location', 'payment', 'creator', 'settlements.payment', 'settlements.payrollLine.run']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('repay')->label(__('Cash repayment'))->icon('heroicon-o-arrow-down-tray')->color('success')
                ->visible(fn (EmployeeAdvance $record) => $record->voided_at === null && Decimal::of($record->remaining)->isPositive()
                    && auth()->user()?->can('payments.create'))
                ->schema(fn (EmployeeAdvance $record) => [
                    TextInput::make('amount')->label(__('Amount'))->numeric()->minValue(0.01)->maxValue((float) $record->remaining)
                        ->default((float) $record->remaining)->required(),
                    Select::make('payment_method_id')->label(__('Payment method'))->required()
                        ->options(fn () => PaymentMethod::query()->where('is_active', true)->where('type', '!=', PaymentMethodType::Advance)->orderBy('sort')->pluck('name', 'id'))
                        ->default(fn () => PaymentMethod::query()->where('type', PaymentMethodType::Cash)->value('id')),
                    DateTimePicker::make('paid_at')->label(__('Date'))->default(now())->required(),
                ])
                ->action(function (EmployeeAdvance $record, array $data) {
                    DomainGuard::run(fn () => app(RepayAdvance::class)->handle($record, $data['amount'], (int) $data['payment_method_id'],
                        Carbon::parse($data['paid_at'])));
                    Notification::make()->success()->title(__('Saved'))->send();
                    $this->refreshRecord();
                }),
            Action::make('voidRepayment')->label(__('Void a repayment'))->icon('heroicon-o-arrow-uturn-left')->color('gray')
                ->visible(fn (EmployeeAdvance $record) => $record->voided_at === null && $record->settlements->whereNotNull('payment_id')->isNotEmpty()
                    && auth()->user()?->can('payments.void'))
                ->schema(fn (EmployeeAdvance $record) => [
                    Select::make('settlement_id')->label(__('Repayment'))->required()
                        ->options($record->settlements->whereNotNull('payment_id')
                            ->mapWithKeys(fn ($s) => [$s->id => $s->payment->number.' — '.number_format((float) $s->amount, 2)])),
                    TextInput::make('reason')->label(__('Void reason'))->required()->maxLength(255),
                ])
                ->action(function (EmployeeAdvance $record, array $data) {
                    $settlement = $record->settlements()->findOrFail($data['settlement_id']);
                    DomainGuard::run(fn () => app(VoidAdvance::class)->voidRepayment($settlement, $data['reason']));
                    Notification::make()->success()->title(__('Voided'))->send();
                    $this->refreshRecord();
                }),
            Action::make('printVoucher')->label(__('Print voucher'))->icon('heroicon-o-printer')->color('gray')
                ->visible(fn (EmployeeAdvance $record) => $record->payment_id !== null)
                ->url(fn (EmployeeAdvance $record) => route('vouchers.print', $record->payment_id), true),
            DocumentActions::void(fn (EmployeeAdvance $r, string $reason) => app(VoidAdvance::class)->handle($r, $reason), 'advances.void', $this)
                ->modalDescription(__('The payment voucher of this advance will be voided too.')),
            ActivityHistoryAction::make(),
        ];
    }

    public function refreshRecord(): void
    {
        $this->record = $this->resolveRecord($this->getRecord()->getKey());
    }
}
