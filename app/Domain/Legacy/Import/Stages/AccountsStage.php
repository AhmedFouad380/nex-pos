<?php

namespace App\Domain\Legacy\Import\Stages;

use App\Domain\Finance\Enums\AccountSubType as Role;
use App\Domain\Finance\Enums\AccountType;
use App\Domain\Finance\Enums\PaymentMethodType;
use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\PaymentMethod;
use App\Domain\Finance\Services\SystemAccounts;
use App\Domain\Legacy\Import\Custody;
use App\Domain\Legacy\Import\ImportContext;
use App\Domain\Legacy\Import\LegacyPackage;
use App\Domain\Legacy\Import\Stage;
use Illuminate\Support\Facades\DB;

/**
 * The old chart of accounts becomes the chart of accounts — same codes and names the accountant knows.
 * The posting roles (cash, receivables, sales…) are put on the old accounts named in its GeneralSetting;
 * roles the old system had no account for get a new account under the right parent.
 *
 * Accounts of individual customers / suppliers / employees are NOT accounts here: they become contacts
 * (ContactsStage) under one control account each — the old parents 102003 / 201001 / 102004.
 */
final class AccountsStage implements Stage
{
    /** old parent account of per-contact accounts => [role, contact type] */
    public const CONTACT_PARENTS = [
        '102003' => [Role::Receivable, 'customer'],
        '201001' => [Role::Payable, 'supplier'],
        '102004' => [Role::EmployeeAccounts, 'employee'],
    ];

    public function name(): string
    {
        return 'accounts';
    }

    public function run(LegacyPackage $package, ImportContext $context): void
    {
        $accounts = collect($package->all('ChartOfAccount'))->keyBy(fn ($a) => (string) $a['AccountId']);
        if ($accounts->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($accounts, $package, $context) {
            if ($context->newId('ChartOfAccount', '1') === null) {
                $this->clearDefaultChart($context);
            }

            $children = $accounts->groupBy(fn ($a) => (string) ($a['AccountIdParent'] ?? '0'));
            // parents before children
            foreach ($accounts->sortBy(fn ($a) => [(int) $a['AccountLevel'], (string) $a['AccountId']]) as $id => $row) {
                $parentId = (string) ($row['AccountIdParent'] ?? '0');
                if (isset(self::CONTACT_PARENTS[$parentId])) {
                    continue; // a single customer / supplier / employee → contact
                }
                $isControl = isset(self::CONTACT_PARENTS[$id]);
                $account = Account::query()->find($context->newId('ChartOfAccount', $id)) ?? new Account(['is_system' => false]);
                $account->fill([
                    'code' => $id,
                    'name' => trim((string) ($row['NameA'] ?: $row['NameE'] ?: $id)),
                    'parent_id' => $parentId !== '0' ? $context->newId('ChartOfAccount', $parentId) : null,
                    'type' => $this->type($id),
                    'is_postable' => $isControl || ! $children->has($id),
                    'is_active' => true,
                ])->save();
                $context->remember('ChartOfAccount', $id, $account);
                $context->count($this->name(), 'accounts');
            }

            $this->assignRoles($package, $context, $accounts->keys()->all());
            $this->custodyBoxes($package, $context, $accounts->all());
            $this->linkPaymentMethods($package, $context);
        });

        app(SystemAccounts::class)->flush();
    }

    /** The default chart is replaced on the first import, as long as nothing was posted on it. */
    private function clearDefaultChart(ImportContext $context): void
    {
        if (DB::table('journal_lines')->exists()) {
            $context->warn(__('legacy.warnings.chart_kept'));

            return;
        }
        DB::table('expense_categories')->whereNotExists(fn ($q) => $q->from('expenses')->whereColumn('expenses.expense_category_id', 'expense_categories.id'))->delete();
        DB::table('expense_categories')->update(['account_id' => null]);
        DB::table('payment_methods')->update(['account_id' => null]);
        DB::table('location_payment_method')->update(['account_id' => null]);
        DB::table('cash_registers')->update(['cash_account_id' => null]);
        DB::table('accounts')->update(['parent_id' => null]); // accounts reference their parent
        DB::table('accounts')->delete();
    }

    /** Account type from the first digit of the old code (1 assets, 2 liabilities, 3 income, 4 expenses, 5/6 equity). */
    private function type(string $code): AccountType
    {
        return match ($code[0]) {
            '1' => AccountType::Asset,
            '2' => AccountType::Liability,
            '3' => AccountType::Income,
            '4' => AccountType::Expense,
            default => AccountType::Equity,
        };
    }

    /** @param list<string> $codes */
    private function assignRoles(LegacyPackage $package, ImportContext $context, array $codes): void
    {
        $settings = $package->all('GeneralSetting')[0] ?? [];
        $setRole = function (?string $code, Role $role) use ($context): bool {
            $id = $code ? $context->newId('ChartOfAccount', $code) : null;
            $account = $id ? Account::query()->find($id) : null;
            if (! $account || ! $account->is_postable) {
                return false;
            }
            $account->update(['sub_type' => $role, 'is_system' => true]);

            return true;
        };

        foreach (self::CONTACT_PARENTS as $code => [$role]) {
            $setRole((string) $code, $role); // numeric-looking array keys come back as ints
        }
        // every cash box / bank of the old system can receive money
        foreach ($codes as $code) {
            $parent = substr($code, 0, 6);
            if (strlen($code) > 6 && $parent === '102001') {
                $setRole($code, Role::Cash);
            } elseif (strlen($code) > 6 && $parent === '102002') {
                $setRole($code, Role::Bank);
            }
        }
        // stock and cost-of-sales accounts of each store
        foreach ($package->all('Store') as $store) {
            $setRole((string) ($store['Stock'] ?? ''), Role::Inventory);
            $setRole((string) ($store['CostOfSales'] ?? ''), Role::Cogs);
        }

        $named = [
            'Sales' => Role::Sales, 'ReturnedSales' => Role::SalesReturn, 'Rebate' => Role::SalesDiscount,
            'DiscountEarned' => Role::PurchaseDiscount, 'Rounding' => Role::Rounding, 'Taxes' => Role::TaxOutput,
            'Expenses' => Role::OperatingExpense, 'ProfitLoss' => Role::RetainedEarnings,
        ];
        foreach ($named as $setting => $role) {
            $setRole(isset($settings[$setting]) ? (string) $settings[$setting] : null, $role);
        }

        // well-known old accounts without a setting
        foreach (['407' => Role::InventoryAdjustment, '306' => Role::OtherIncome, '501001' => Role::Capital] as $code => $role) {
            $setRole((string) $code, $role);
        }

        // roles the old system had no account for: a new account under the matching old parent
        $missing = [
            [Role::GoodsInTransit, '102', 'بضاعة في الطريق'],
            [Role::TaxInput, '102', 'ضريبة القيمة المضافة - مشتريات'],
            [Role::AccruedExpenses, '201', 'مصروفات مستحقة'],
            [Role::OpeningBalance, '5', 'الأرصدة الافتتاحية'],
            [Role::ShippingIncome, '3', 'إيرادات الشحن والتغليف'],
            [Role::CashOverShort, '4', 'عجز وزيادة الخزينة'],
            [Role::Cash, '102001', 'الخزينة'],
            [Role::Inventory, '102005', 'المخزون'],
            [Role::Cogs, '4', 'تكلفة البضاعة المباعة'],
            [Role::Sales, '3', 'المبيعات'],
            [Role::SalesReturn, '3', 'مردودات المبيعات'],
            [Role::SalesDiscount, '3', 'الخصم المسموح به'],
            [Role::PurchaseDiscount, '3', 'الخصم المكتسب'],
            [Role::Rounding, '4', 'فروق التقريب'],
            [Role::InventoryAdjustment, '4', 'فروقات وتسويات المخزون'],
            [Role::TaxOutput, '201', 'ضريبة القيمة المضافة - مبيعات'],
            [Role::OperatingExpense, '4', 'مصروفات متنوعة'],
            [Role::Salaries, '4', 'الرواتب والأجور'],
            [Role::OtherIncome, '3', 'إيرادات أخرى'],
            [Role::Capital, '5', 'رأس المال'],
            [Role::RetainedEarnings, '5', 'الأرباح المحتجزة'],
        ];
        foreach ($missing as [$role, $parentCode, $name]) {
            if (Account::query()->where('sub_type', $role)->exists()) {
                continue;
            }
            $parent = Account::query()->find($context->newId('ChartOfAccount', $parentCode));
            if (! $parent) {
                continue;
            }
            Account::query()->create([
                'parent_id' => $parent->id, 'code' => $this->nextCode($parent), 'name' => $name, 'type' => $parent->type,
                'sub_type' => $role, 'is_system' => true, 'is_postable' => true, 'is_active' => true,
            ]);
            $parent->is_postable && $parent->update(['is_postable' => false]);
            $context->count($this->name(), 'role accounts created');
        }
    }

    /**
     * A cash box per cashier custody, under the old cash parent, with the old account code.
     *
     * @param  array<string, array<string, mixed>>  $accounts
     */
    private function custodyBoxes(LegacyPackage $package, ImportContext $context, array $accounts): void
    {
        $parent = $context->newId('ChartOfAccount', '102001');
        foreach (Custody::codes($package) as $code) {
            $box = Account::query()->find($context->newId('Custody', $code)) ?? new Account(['is_system' => true]);
            $box->fill([
                'code' => $code, 'name' => __('legacy.custody_name', ['name' => trim((string) ($accounts[$code]['NameA'] ?? $code))]),
                'parent_id' => $parent, 'type' => AccountType::Asset, 'sub_type' => Role::Cash, 'is_postable' => true, 'is_active' => true,
            ])->save();
            $context->remember('Custody', $code, $box);
            $context->count($this->name(), 'custody boxes');
        }
    }

    /** First free child code: 3 → 321, 102 → 102099…, following the old numbering width. */
    private function nextCode(Account $parent): string
    {
        $siblings = Account::query()->where('parent_id', $parent->id)->pluck('code')->filter(fn ($c) => ctype_digit((string) $c));
        if ($siblings->isNotEmpty()) {
            $width = strlen((string) $siblings->first());

            return str_pad((string) ((int) $siblings->max() + 1), $width, '0', STR_PAD_LEFT);
        }

        return $parent->code.(strlen($parent->code) >= 3 ? '001' : '01');
    }

    /** Cash method → the main box; each location gets its own branch box (GeneralSetting per branch). */
    private function linkPaymentMethods(LegacyPackage $package, ImportContext $context): void
    {
        $cash = PaymentMethod::query()->where('type', PaymentMethodType::Cash)->first();
        $mainBox = $context->newId('ChartOfAccount', (string) ($package->all('GeneralSetting')[0]['BoxId'] ?? ''));
        $mainBank = $context->newId('ChartOfAccount', (string) ($package->all('GeneralSetting')[0]['BankId'] ?? ''));
        $cash?->update(['account_id' => $mainBox ?? app(SystemAccounts::class)->id(Role::Cash)]);
        PaymentMethod::query()->where('type', '!=', PaymentMethodType::Cash)->update(['account_id' => $mainBank ?? $mainBox]);
    }
}
