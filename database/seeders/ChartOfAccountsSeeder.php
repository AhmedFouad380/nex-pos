<?php

namespace Database\Seeders;

use App\Domain\Finance\Enums\AccountSubType as Sub;
use App\Domain\Finance\Enums\AccountType as Type;
use App\Domain\Finance\Models\Account;
use Illuminate\Database\Seeder;

/**
 * Default chart of accounts. Accounts with a sub_type are "system" accounts that
 * posting rules look up (e.g. Sub::Receivable) — they can be renamed but not deleted.
 */
class ChartOfAccountsSeeder extends Seeder
{
    /** [code, name, type, sub_type|null, children[]] */
    private function tree(): array
    {
        return [
            ['1', 'الأصول', Type::Asset, null, [
                ['11', 'الأصول المتداولة', Type::Asset, null, [
                    ['1101', 'الخزينة الرئيسية', Type::Asset, Sub::Cash],
                    ['1102', 'البنك', Type::Asset, Sub::Bank],
                    ['1103', 'المحافظ الإلكترونية', Type::Asset, Sub::Bank],
                    ['1104', 'العملاء', Type::Asset, Sub::Receivable],
                    ['1105', 'شيكات تحت التحصيل', Type::Asset, Sub::Other],
                    ['1106', 'المخزون', Type::Asset, Sub::Inventory],
                    ['1107', 'ضريبة القيمة المضافة - مشتريات', Type::Asset, Sub::TaxInput],
                    ['1108', 'دفعات مقدمة للموردين', Type::Asset, Sub::SupplierAdvance],
                    ['1109', 'بضاعة في الطريق', Type::Asset, Sub::GoodsInTransit],
                    ['1110', 'حسابات الموظفين (سلف وعهد)', Type::Asset, Sub::EmployeeAccounts],
                ]],
                ['12', 'الأصول الثابتة', Type::Asset, null, [
                    ['1201', 'الأثاث والتجهيزات', Type::Asset, null],
                    ['1202', 'الأجهزة والمعدات', Type::Asset, null],
                ]],
            ]],
            ['2', 'الخصوم', Type::Liability, null, [
                ['21', 'الخصوم المتداولة', Type::Liability, null, [
                    ['2101', 'الموردين', Type::Liability, Sub::Payable],
                    ['2102', 'ضريبة القيمة المضافة - مبيعات', Type::Liability, Sub::TaxOutput],
                    ['2103', 'دفعات مقدمة من العملاء', Type::Liability, Sub::CustomerAdvance],
                    ['2104', 'شيكات مستحقة الدفع', Type::Liability, null],
                    ['2105', 'مصروفات مستحقة', Type::Liability, Sub::AccruedExpenses],
                ]],
            ]],
            ['3', 'حقوق الملكية', Type::Equity, null, [
                ['3101', 'رأس المال', Type::Equity, Sub::Capital],
                ['3102', 'الأرباح المحتجزة', Type::Equity, Sub::RetainedEarnings],
                ['3103', 'الأرصدة الافتتاحية', Type::Equity, Sub::OpeningBalance],
            ]],
            ['4', 'الإيرادات', Type::Income, null, [
                ['4101', 'المبيعات', Type::Income, Sub::Sales],
                ['4102', 'مردودات المبيعات', Type::Income, Sub::SalesReturn],
                ['4103', 'الخصم المسموح به', Type::Income, Sub::SalesDiscount],
                ['4104', 'إيرادات الشحن والتغليف', Type::Income, Sub::ShippingIncome],
                ['4105', 'الخصم المكتسب', Type::Income, Sub::PurchaseDiscount],
                ['4106', 'إيرادات أخرى', Type::Income, Sub::OtherIncome],
            ]],
            ['5', 'المصروفات', Type::Expense, null, [
                ['51', 'تكلفة المبيعات', Type::Expense, null, [
                    ['5101', 'تكلفة البضاعة المباعة', Type::Expense, Sub::Cogs],
                    ['5102', 'فروقات وتسويات المخزون', Type::Expense, Sub::InventoryAdjustment],
                ]],
                ['52', 'المصروفات التشغيلية', Type::Expense, null, [
                    ['5201', 'الإيجار', Type::Expense, null],
                    ['5202', 'الرواتب والأجور', Type::Expense, Sub::Salaries],
                    ['5203', 'الكهرباء والمياه', Type::Expense, null],
                    ['5204', 'النقل والمواصلات', Type::Expense, null],
                    ['5205', 'الصيانة', Type::Expense, null],
                    ['5206', 'الاتصالات والإنترنت', Type::Expense, null],
                    ['5207', 'الدعاية والإعلان', Type::Expense, null],
                    ['5208', 'عمولات التحويل والمصاريف البنكية', Type::Expense, null],
                    ['5299', 'مصروفات متنوعة', Type::Expense, Sub::OperatingExpense],
                ]],
                ['5301', 'عجز وزيادة الخزينة', Type::Expense, Sub::CashOverShort],
                ['5302', 'فروق التقريب', Type::Expense, Sub::Rounding],
            ]],
        ];
    }

    public function run(): void
    {
        foreach ($this->tree() as $node) {
            $this->upsert($node, null);
        }
    }

    private function upsert(array $node, ?Account $parent): void
    {
        [$code, $name, $type, $subType] = $node;
        $children = $node[4] ?? [];

        $account = Account::query()->firstOrNew(['code' => $code]);
        if (! $account->exists) {
            $account->name = $name; // never overwrite a name the client renamed
        }
        $account->fill([
            'parent_id' => $parent?->id,
            'type' => $type,
            'sub_type' => $subType,
            'is_system' => $subType !== null || $children !== [],
            'is_postable' => $children === [],
            'is_active' => true,
        ])->save();

        foreach ($children as $child) {
            $this->upsert($child, $account);
        }
    }
}
