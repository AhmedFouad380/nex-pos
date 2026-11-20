<?php

namespace App\Filament\Admin\Resources\PayrollRuns\Pages;

use App\Domain\Finance\Enums\PaymentMethodType;
use App\Domain\Finance\Models\PaymentMethod;
use App\Domain\Payroll\Actions\ApprovePayroll;
use App\Domain\Payroll\Actions\PayPayroll;
use App\Domain\Payroll\Actions\ReopenPayroll;
use App\Domain\Payroll\Enums\PayrollStatus;
use App\Domain\Payroll\Models\PayrollLine;
use App\Domain\Payroll\Models\PayrollRun;
use App\Filament\Actions\ActivityHistoryAction;
use App\Filament\Admin\Resources\PayrollRuns\PayrollRunResource;
use App\Filament\Support\DocumentActions;
use App\Filament\Support\DomainGuard;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ViewPayrollRun extends ViewRecord
{
    protected static string $resource = PayrollRunResource::class;

    public function getTitle(): string
    {
        $record = $this->getRecord();

        return $record instanceof PayrollRun ? __('Payroll :month', ['month' => $record->periodLabel()]).' — '.$record->number : parent::getTitle();
    }

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['lines.contact', 'lines.payment', 'location', 'creator', 'approver']);
    }

    protected function getHeaderActions(): array
    {
        $live = fn (PayrollRun $r) => $r->voided_at === null;

        return [
            EditAction::make()->label(__('Edit lines')),
            DocumentActions::step('approve', __('Approve'), 'heroicon-o-check-badge', 'success',
                fn (PayrollRun $r) => $live($r) && $r->status === PayrollStatus::Draft && auth()->user()?->can('payroll.approve'),
                fn (PayrollRun $r) => app(ApprovePayroll::class)->handle($r), $this)
                ->modalDescription(__('Posts the salaries to the accounts and deducts the advances. Lines can no longer be edited unless you reopen it.')),
            Action::make('pay')->label(__('Pay salaries'))->icon('heroicon-o-banknotes')->color('success')
                ->visible(function (PayrollRun $r) use ($live): bool {
                    $user = auth()->user();

                    return $live($r) && in_array($r->status, [PayrollStatus::Approved, PayrollStatus::PartiallyPaid], true)
                        && $user !== null && $user->can('payroll.pay') && $user->can('payments.create');
                })
                ->schema(fn (PayrollRun $record) => [
                    CheckboxList::make('line_ids')->label(__('Employees'))->required()->bulkToggleable()->columns(2)
                        ->options(fn () => $record->lines->reject->isPaid()->sortBy('contact.name')
                            ->mapWithKeys(fn (PayrollLine $l) => [$l->id => $l->contact->name.' — '.number_format((float) $l->net, 2)]))
                        ->default(fn () => $record->lines->reject->isPaid()->pluck('id')->all()),
                    Select::make('payment_method_id')->label(__('Payment method'))->required()
                        ->options(fn () => PaymentMethod::query()->where('is_active', true)->where('type', '!=', PaymentMethodType::Advance)->orderBy('sort')->pluck('name', 'id'))
                        ->default(fn () => PaymentMethod::query()->where('type', PaymentMethodType::Cash)->value('id')),
                    DateTimePicker::make('paid_at')->label(__('Date'))->default(now())->required(),
                ])
                ->modalDescription(__('One payment voucher per employee, for their net salary.'))
                ->action(function (PayrollRun $record, array $data) {
                    $count = DomainGuard::run(fn () => app(PayPayroll::class)->handle($record, (int) $data['payment_method_id'],
                        array_map('intval', $data['line_ids']), Carbon::parse($data['paid_at'])));
                    Notification::make()->success()->title(__(':count salaries paid', ['count' => $count]))->send();
                    $this->refreshRecord();
                }),
            ActionGroup::make([
                Action::make('printSheet')->label(__('Print payroll sheet'))->icon('heroicon-o-printer')
                    ->url(fn (PayrollRun $r) => route('payroll.print', $r), true),
                Action::make('printPayslips')->label(__('Print payslips'))->icon('heroicon-o-document-duplicate')
                    ->url(fn (PayrollRun $r) => route('payroll.payslips', $r), true),
            ])->label(__('Print'))->icon('heroicon-o-printer')->color('gray')->button(),
            DocumentActions::step('reopen', __('Reopen'), 'heroicon-o-lock-open', 'warning',
                fn (PayrollRun $r) => $live($r) && $r->status === PayrollStatus::Approved && auth()->user()?->can('payroll.approve'),
                fn (PayrollRun $r) => app(ReopenPayroll::class)->handle($r), $this)
                ->modalDescription(__('Reverses the salaries entry and gives the advance deductions back, so the lines can be edited again.')),
            DocumentActions::void(fn (PayrollRun $r, string $reason) => app(ReopenPayroll::class)->void($r, $reason), 'payroll.void', $this)
                ->modalDescription(__('Reverses the salaries entry and gives the advance deductions back. Void the payment vouchers first if salaries were paid.')),
            ActivityHistoryAction::make(),
        ];
    }

    public function refreshRecord(): void
    {
        $this->record = $this->resolveRecord($this->getRecord()->getKey());
    }
}
