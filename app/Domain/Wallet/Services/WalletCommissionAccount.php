<?php

namespace App\Domain\Wallet\Services;

use App\Domain\Finance\Enums\AccountSubType;
use App\Domain\Finance\Enums\AccountType;
use App\Domain\Finance\Models\Account;
use Illuminate\Support\Facades\DB;

/**
 * The income account the wallet service commission goes to ("إيرادات عمولات المحافظ"). Made the first time it is
 * needed, next to "other income" (or under the income root), with the next free code — so imported charts get it too.
 */
final class WalletCommissionAccount
{
    public function id(): int
    {
        $existing = Account::query()->where('sub_type', AccountSubType::WalletCommission)->where('is_active', true)->value('id');
        if ($existing !== null) {
            return (int) $existing;
        }

        return DB::transaction(function () {
            $sibling = Account::query()->where('sub_type', AccountSubType::OtherIncome)->orderBy('code')->first();
            $parent = $sibling?->parent_id
                ? Account::query()->find($sibling->parent_id)
                : Account::query()->where('type', AccountType::Income)->whereNull('parent_id')->orderBy('code')->first();

            return Account::query()->create([
                'parent_id' => $parent?->id,
                'code' => $this->nextCode($parent),
                'name' => __('enums.account_sub_type.wallet_commission'),
                'type' => AccountType::Income,
                'sub_type' => AccountSubType::WalletCommission,
                'is_system' => true,
                'is_postable' => true,
                'is_active' => true,
            ])->id;
        });
    }

    private function nextCode(?Account $parent): string
    {
        $siblings = Account::query()->where('parent_id', $parent?->id)->pluck('code')->filter(fn ($c) => ctype_digit((string) $c));
        $code = $siblings->isEmpty()
            ? ($parent->code ?? '4').'01'
            : str_pad((string) ((int) $siblings->max() + 1), strlen((string) $siblings->first()), '0', STR_PAD_LEFT);
        while (Account::query()->where('code', $code)->exists()) {
            $code = (string) ((int) $code + 1);
        }

        return $code;
    }
}
