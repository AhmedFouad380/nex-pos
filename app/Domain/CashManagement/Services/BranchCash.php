<?php

namespace App\Domain\CashManagement\Services;

use App\Domain\CashManagement\Enums\CashSessionStatus;
use App\Domain\CashManagement\Models\CashRegister;
use App\Domain\CashManagement\Models\CashSession;
use App\Domain\Finance\Enums\PaymentMethodType;
use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\PaymentMethod;
use App\Domain\Foundation\Models\Location;
use App\Support\Decimal;
use App\Support\DomainException;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/**
 * خزنة الفرع: the cash account a branch's drawer posts to (its cash method's account at that branch, else the
 * method's own), which branches use which box, and the book balance of boxes.
 */
final class BranchCash
{
    public function cashMethod(): ?PaymentMethod
    {
        return PaymentMethod::query()->where('type', PaymentMethodType::Cash)->orderBy('sort')->orderBy('id')->first();
    }

    /** The box a branch's cash goes to. */
    public function boxFor(int $locationId): ?int
    {
        $method = $this->cashMethod();
        if ($method === null) {
            return null;
        }
        $id = DB::table('location_payment_method')->where('location_id', $locationId)->where('payment_method_id', $method->id)->value('account_id')
            ?? $method->account_id;

        return $id === null ? null : (int) $id;
    }

    /** @return array<int, list<int>> box account id => the branches whose cash goes to it */
    public function branchesByBox(): array
    {
        $map = [];
        foreach (Location::query()->orderBy('id')->pluck('id') as $locationId) {
            if ($box = $this->boxFor((int) $locationId)) {
                $map[$box][] = (int) $locationId;
            }
        }

        return $map;
    }

    /**
     * Makes $account the box of a branch. While a shift is open there it waits for that shift to close (the
     * shift's cash and its closing difference stay on one box): false is returned then.
     */
    public function assign(Account $account, int $locationId): bool
    {
        $method = $this->cashMethod() ?? throw new DomainException(__('cash.errors.no_cash_method'));
        if ($shift = $this->openShift($locationId)) {
            $shift->cashRegister->update(['next_cash_account_id' => $account->id]);

            return false;
        }
        DB::table('location_payment_method')->updateOrInsert(
            ['location_id' => $locationId, 'payment_method_id' => $method->id],
            ['account_id' => $account->id],
        );
        CashRegister::query()->where('location_id', $locationId)->update(['next_cash_account_id' => null]);

        return true;
    }

    /** Called when a shift closes: the box chosen meanwhile becomes the branch's box. */
    public function applyNextBox(CashRegister $register): void
    {
        $register->refresh();
        if ($register->next_cash_account_id !== null && $account = Account::query()->find($register->next_cash_account_id)) {
            $this->assign($account, $register->location_id);
        }
    }

    /** @return array<int, list<int>> box account id => branches that switch to it when their open shift closes */
    public function nextBoxes(): array
    {
        $map = [];
        foreach (CashRegister::query()->whereNotNull('next_cash_account_id')->get(['location_id', 'next_cash_account_id']) as $register) {
            $map[(int) $register->next_cash_account_id][] = (int) $register->location_id;
        }

        return $map;
    }

    public function openShift(int $locationId): ?CashSession
    {
        return CashSession::query()->where('status', CashSessionStatus::Open)
            ->whereHas('cashRegister', fn ($query) => $query->where('location_id', $locationId))
            ->with(['user', 'cashRegister'])->latest('opened_at')->first();
    }

    /**
     * Book balance (debit − credit) of each account.
     *
     * @param  list<int>  $accountIds
     * @return array<int, BigDecimal>
     */
    public function balances(array $accountIds): array
    {
        $sums = DB::table('journal_lines')->whereIn('account_id', $accountIds)->groupBy('account_id')
            ->selectRaw('account_id, sum(debit) - sum(credit) as net')->pluck('net', 'account_id');

        return collect($accountIds)->mapWithKeys(fn (int $id) => [$id => Decimal::of((string) ($sums[$id] ?? '0'))])->all();
    }
}
