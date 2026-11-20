<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Finance\Enums\AccountSubType;
use App\Domain\Finance\Enums\AccountType;
use App\Domain\Finance\Services\GeneralLedger;
use App\Support\Decimal;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * قائمة الدخل والميزانية, read from the general ledger. Amounts are shown in each account's natural
 * direction (income and liabilities positive when credit), so sections simply add up.
 */
final class FinancialStatements
{
    /** Revenue accounts that make up "net sales" (contra accounts included). */
    private const SALES_ROLES = [AccountSubType::Sales, AccountSubType::SalesReturn, AccountSubType::SalesDiscount, AccountSubType::ShippingIncome];

    /** Expense accounts that make up the cost of sales. */
    private const COST_ROLES = [AccountSubType::Cogs, AccountSubType::InventoryAdjustment];

    public function __construct(private readonly GeneralLedger $ledger) {}

    /**
     * @return array{sections: list<array{title: string, rows: list<array{code: string, name: string, amount: string}>, total: string}>,
     *     net_sales: string, cost_of_sales: string, gross_profit: string, net_profit: string}
     */
    public function incomeStatement(CarbonInterface $from, CarbonInterface $to): array
    {
        $leaves = $this->ledger->trialBalance($from, $to)->filter(fn ($r) => $r['account']->is_postable);
        // period movement, positive in the account's natural direction
        $amount = fn (array $r) => $r['account']->type->isDebitNormal()
            ? Decimal::of($r['debit'])->minus(Decimal::of($r['credit']))
            : Decimal::of($r['credit'])->minus(Decimal::of($r['debit']));
        // contra-revenue (returns, discounts) are income accounts with debit balances → negative here, as they should be
        $pick = fn (\Closure $test) => $leaves->filter(fn ($r) => $test($r['account']))
            ->map(fn ($r) => ['code' => $r['account']->code, 'name' => $r['account']->name, 'amount' => Decimal::toDb($amount($r), 2)])
            ->reject(fn ($row) => Decimal::of($row['amount'])->isZero())->values();

        $sales = $pick(fn ($a) => $a->type === AccountType::Income && in_array($a->sub_type, self::SALES_ROLES, true));
        $cost = $pick(fn ($a) => $a->type === AccountType::Expense && in_array($a->sub_type, self::COST_ROLES, true));
        $otherIncome = $pick(fn ($a) => $a->type === AccountType::Income && ! in_array($a->sub_type, self::SALES_ROLES, true));
        $expenses = $pick(fn ($a) => $a->type === AccountType::Expense && ! in_array($a->sub_type, self::COST_ROLES, true));

        $total = fn (Collection $rows) => $rows->reduce(fn (BigDecimal $c, $r) => $c->plus(Decimal::of($r['amount'])), BigDecimal::zero());
        $gross = $total($sales)->minus($total($cost));
        $net = $gross->plus($total($otherIncome))->minus($total($expenses));

        return [
            'sections' => [
                ['title' => __('Net sales'), 'rows' => $sales->all(), 'total' => Decimal::toDb($total($sales), 2)],
                ['title' => __('Cost of sales'), 'rows' => $cost->all(), 'total' => Decimal::toDb($total($cost), 2)],
                ['title' => __('Other income'), 'rows' => $otherIncome->all(), 'total' => Decimal::toDb($total($otherIncome), 2)],
                ['title' => __('Operating expenses'), 'rows' => $expenses->all(), 'total' => Decimal::toDb($total($expenses), 2)],
            ],
            'net_sales' => Decimal::toDb($total($sales), 2),
            'cost_of_sales' => Decimal::toDb($total($cost), 2),
            'gross_profit' => Decimal::toDb($gross, 2),
            'net_profit' => Decimal::toDb($net, 2),
        ];
    }

    /**
     * Balances on a date. Profit not yet closed into retained earnings is shown inside equity.
     *
     * @return array{sections: list<array{title: string, rows: list<array{code: string, name: string, depth: int, amount: string, heading: bool}>, total: string}>,
     *     earnings: string, balanced: bool}
     */
    public function balanceSheet(CarbonInterface $asOf): array
    {
        $rows = $this->ledger->trialBalance(null, $asOf);
        $natural = fn (array $r) => $r['account']->type->isDebitNormal() ? Decimal::of($r['closing']) : Decimal::of($r['closing'])->negated();

        $section = function (AccountType $type, string $title) use ($rows, $natural) {
            $list = $rows->filter(fn ($r) => $r['account']->type === $type && ! Decimal::of($r['closing'])->isZero())
                ->map(fn ($r) => ['code' => $r['account']->code, 'name' => $r['account']->name, 'depth' => $r['depth'],
                    'amount' => Decimal::toDb($natural($r), 2), 'heading' => ! $r['account']->is_postable])->values();
            $total = $rows->filter(fn ($r) => $r['account']->type === $type && $r['account']->is_postable)
                ->reduce(fn (BigDecimal $c, $r) => $c->plus($natural($r)), BigDecimal::zero());

            return ['title' => $title, 'rows' => $list->all(), 'total' => $total];
        };

        $assets = $section(AccountType::Asset, __('Assets'));
        $liabilities = $section(AccountType::Liability, __('Liabilities'));
        $equity = $section(AccountType::Equity, __('Equity'));

        // Σ income − Σ expenses since the beginning
        $earnings = $rows->filter(fn ($r) => $r['account']->is_postable && in_array($r['account']->type, [AccountType::Income, AccountType::Expense], true))
            ->reduce(fn (BigDecimal $c, $r) => $c->minus(Decimal::of($r['closing'])), BigDecimal::zero());
        $equity['rows'][] = ['code' => '', 'name' => __('Profit / loss not yet closed'), 'depth' => 1, 'amount' => Decimal::toDb($earnings, 2), 'heading' => false];
        $equity['total'] = $equity['total']->plus($earnings);

        $balanced = $assets['total']->isEqualTo($liabilities['total']->plus($equity['total']));

        return [
            'sections' => array_map(fn ($s) => [...$s, 'total' => Decimal::toDb($s['total'], 2)], [$assets, $liabilities, $equity]),
            'earnings' => Decimal::toDb($earnings, 2),
            'balanced' => $balanced,
        ];
    }
}
