<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Enums\AccountSubType;
use App\Domain\Finance\Enums\AccountType;
use App\Domain\Finance\Models\Account;
use App\Support\DomainException;
use Illuminate\Support\Facades\DB;

/** Adds or edits an account in the chart. System accounts keep their place, type and role. */
final class SaveAccount
{
    public function handle(array $data, ?Account $account = null): Account
    {
        return DB::transaction(function () use ($data, $account) {
            $parent = filled($data['parent_id'] ?? null) ? Account::query()->lockForUpdate()->findOrFail($data['parent_id']) : null;

            if ($account?->is_system) {
                // only cosmetic fields of a system account may change
                $account->update(array_intersect_key($data, array_flip(['code', 'name', 'is_active', 'bank_name', 'account_number', 'note'])));

                return $account;
            }

            if ($parent && $account && ($parent->is($account) || in_array($parent->id, $this->descendants($account), true))) {
                throw new DomainException(__('finance.errors.parent_loop'));
            }
            if ($parent && $parent->is_postable) {
                // a parent becomes a heading — not possible once it has entries of its own
                if ($parent->journalLines()->exists()) {
                    throw new DomainException(__('finance.errors.parent_has_entries', ['account' => $parent->name]));
                }
                $parent->update(['is_postable' => false]);
            }

            $subType = $data['sub_type'] ?? null;
            $subType = $subType instanceof AccountSubType ? $subType : ($subType ? AccountSubType::from($subType) : null);
            $type = $parent->type ?? ($data['type'] instanceof AccountType ? $data['type'] : AccountType::from($data['type']));

            $attributes = [
                'parent_id' => $parent?->id,
                'code' => $data['code'],
                'name' => $data['name'],
                'type' => $type,
                'sub_type' => $subType,
                'is_postable' => (bool) ($data['is_postable'] ?? true),
                'is_active' => (bool) ($data['is_active'] ?? true),
                'bank_name' => $data['bank_name'] ?? null,
                'account_number' => $data['account_number'] ?? null,
                'note' => $data['note'] ?? null,
            ];
            if ($account && ! $attributes['is_postable'] && $account->journalLines()->exists()) {
                throw new DomainException(__('finance.errors.parent_has_entries', ['account' => $account->name]));
            }

            $account ? $account->update($attributes) : $account = Account::query()->create([...$attributes, 'is_system' => false]);

            return $account;
        });
    }

    public function delete(Account $account): void
    {
        if ($account->is_system) {
            throw new DomainException(__('finance.errors.system_account'));
        }
        if ($account->children()->exists() || $account->journalLines()->exists()) {
            throw new DomainException(__('finance.errors.account_in_use'));
        }
        $account->delete();
    }

    /** @return list<int> */
    private function descendants(Account $account): array
    {
        $ids = [];
        $frontier = [$account->id];
        while ($frontier !== []) {
            $frontier = Account::query()->whereIn('parent_id', $frontier)->pluck('id')->all();
            $ids = [...$ids, ...$frontier];
        }

        return $ids;
    }
}
