<?php

namespace App\Domain\Legacy\Import;

use App\Domain\Legacy\Import\Stages\AccountsStage;

/**
 * Cashier custody (عهدة) in the old system: cash sales, vouchers and purchases were paid into / out of an
 * employee account, emptied into the branch box when the shift closed. Accounts used that way often enough
 * become cash boxes of their own here; the person behind them stays an employee for salaries and advances.
 * Accounts used as the money side only now and then are a contact paying out of their own pocket.
 */
final class Custody
{
    /** Uses as the money side of payments / vouchers from which an account counts as a custody box. */
    private const MIN_USES = 50;

    /** @var array<string, array<string, int>> package dir => code => uses */
    private static array $uses = [];

    /** @return array<string, int> old account code => times used as the money side */
    public static function uses(LegacyPackage $package): array
    {
        if (isset(self::$uses[$package->dir])) {
            return self::$uses[$package->dir];
        }
        $parents = [];
        foreach ($package->all('ChartOfAccount') as $a) {
            $parents[(string) $a['AccountId']] = (string) ($a['AccountIdParent'] ?? '');
        }
        $uses = [];
        $count = function (mixed $code) use ($parents, &$uses) {
            $code = (string) $code;
            if ($code !== '' && isset(AccountsStage::CONTACT_PARENTS[$parents[$code] ?? ''])) {
                $uses[$code] = ($uses[$code] ?? 0) + 1;
            }
        };
        foreach ($package->rows('InvoicePayment') as $p) {
            $count($p['AccountId'] ?? null);
        }
        foreach ($package->rows('FinancialTransactions') as $f) {
            $count($f['AccountId'] ?? null);
        }

        return self::$uses[$package->dir] = $uses;
    }

    /** @return list<string> */
    public static function codes(LegacyPackage $package): array
    {
        return array_map('strval', array_keys(array_filter(self::uses($package), fn ($n) => $n >= self::MIN_USES)));
    }

    public static function isCustody(LegacyPackage $package, string $code): bool
    {
        return (self::uses($package)[$code] ?? 0) >= self::MIN_USES;
    }
}
