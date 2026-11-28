<?php

namespace App\Domain\CashManagement\Services;

use App\Domain\CashManagement\Enums\CashSessionStatus;
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
     * Makes $account the box of a branch from now on. Refused while the branch has a shift open, since the shift's
     * cash and its closing difference would end up on two different boxes.
     */
    public function assign(Account $account, int $locationId): void
    {
        $method = $this->cashMethod() ?? throw new DomainException(__('cash.errors.no_cash_method'));
        if ($this->openShift($locationId) !== null) {
            throw new DomainException(__('cash.errors.branch_shift_open', ['branch' => Location::query()->whereKey($locationId)->value('name')]));
        }
        DB::table('location_payment_method')->updateOrInsert(
            ['location_id' => $locationId, 'payment_method_id' => $method->id],
            ['account_id' => $account->id],
        );
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
