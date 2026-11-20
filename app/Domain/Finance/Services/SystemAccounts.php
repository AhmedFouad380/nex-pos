<?php

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Enums\AccountSubType;
use App\Domain\Finance\Models\Account;
use App\Support\DomainException;

/**
 * Finds the account a posting rule needs by its role (sub_type), e.g. "the receivables account".
 * When several accounts share a role (two banks), the one with the lowest code is the default.
 */
final class SystemAccounts
{
    /** @var array<string, int> */
    private array $cache = [];

    public function id(AccountSubType $role): int
    {
        return $this->cache[$role->value] ??= Account::query()
            ->where('sub_type', $role)->where('is_postable', true)->where('is_active', true)
            ->orderBy('code')->value('id')
            ?? throw new DomainException(__('finance.errors.missing_account', ['role' => $role->getLabel()]));
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
