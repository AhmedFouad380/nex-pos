<?php

namespace App\Filament\Admin\Resources\Expenses\Pages;

use App\Domain\Finance\Actions\CreateExpense as CreateExpenseAction;
use App\Filament\Admin\Resources\Expenses\ExpenseResource;
use App\Filament\Concerns\HandlesDomainExceptions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class CreateExpense extends CreateRecord
{
    use HandlesDomainExceptions;

    protected static string $resource = ExpenseResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        abort_unless(array_key_exists((int) $data['location_id'], ExpenseResource::userLocationOptions()), 403);
        $payNow = ($data['pay_now'] ?? false) && filled($data['payment_method_id'] ?? null)
            ? ['payment_method_id' => (int) $data['payment_method_id'], 'amount' => filled($data['paid_amount'] ?? null) ? $data['paid_amount'] : null]
            : null;

        return $this->rescueDomain(fn () => app(CreateExpenseAction::class)->handle([
            'location_id' => (int) $data['location_id'],
            'expense_category_id' => filled($data['expense_category_id'] ?? null) ? (int) $data['expense_category_id'] : null,
            'amount' => $data['amount'],
            'tax_rate_id' => filled($data['tax_rate_id'] ?? null) ? (int) $data['tax_rate_id'] : null,
            'expense_date' => Carbon::parse($data['expense_date']),
            'ref_no' => $data['ref_no'] ?? null,
            'expense_for_user_id' => filled($data['expense_for_user_id'] ?? null) ? (int) $data['expense_for_user_id'] : null,
            'note' => $data['note'] ?? null,
        ], $payNow));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
