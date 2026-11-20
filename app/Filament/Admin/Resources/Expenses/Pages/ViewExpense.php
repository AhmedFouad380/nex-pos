<?php

namespace App\Filament\Admin\Resources\Expenses\Pages;

use App\Domain\Finance\Actions\CreateExpense;
use App\Domain\Finance\Actions\VoidExpense;
use App\Domain\Finance\Models\Expense;
use App\Filament\Actions\ActivityHistoryAction;
use App\Filament\Admin\Resources\Expenses\ExpenseResource;
use App\Filament\Admin\Support\PaymentActions;
use App\Filament\Support\DocumentActions;
use App\Filament\Support\DomainGuard;
use App\Support\Decimal;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ViewExpense extends ViewRecord
{
    protected static string $resource = ExpenseResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return parent::resolveRecord($key)->load(['expenseCategory', 'location', 'creator', 'expenseForUser', 'allocations.payment']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pay')->label(__('Add payment'))->icon('heroicon-o-banknotes')->color('success')
                ->visible(fn (Expense $record) => $record->voided_at === null && Decimal::of($record->due_total)->isPositive()
                    && auth()->user()?->can('payments.create'))
                ->schema(fn (Expense $record) => PaymentActions::fields(fn () => (float) $record->due_total))
                ->action(function (Expense $record, array $data) {
                    DomainGuard::run(fn () => app(CreateExpense::class)->pay($record, (int) $data['payment_method_id'],
                        (string) min((float) $data['amount'], (float) $record->due_total), null, Carbon::parse($data['paid_at'] ?? now())));
                    Notification::make()->success()->title(__('Saved'))->send();
                    $this->refreshRecord();
                }),
            DocumentActions::void(fn (Expense $r, string $reason) => app(VoidExpense::class)->handle($r, $reason), 'expenses.void', $this)
                ->modalDescription(__('The vouchers that paid this expense will be voided too.')),
            ActivityHistoryAction::make(),
        ];
    }

    public function refreshRecord(): void
    {
        $this->record = $this->resolveRecord($this->getRecord()->getKey());
    }
}
