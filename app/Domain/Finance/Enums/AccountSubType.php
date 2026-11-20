<?php

namespace App\Domain\Finance\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasLabel;

enum AccountSubType: string implements HasLabel
{
    use EnumHelpers;

    case Cash = 'cash';
    case Bank = 'bank';
    case Receivable = 'receivable';
    case Payable = 'payable';
    case EmployeeAccounts = 'employee_accounts';
    case Inventory = 'inventory';
    case GoodsInTransit = 'goods_in_transit';
    case Cogs = 'cogs';
    case Sales = 'sales';
    case SalesReturn = 'sales_return';
    case SalesDiscount = 'sales_discount';
    case PurchaseDiscount = 'purchase_discount';
    case TaxOutput = 'tax_output';
    case TaxInput = 'tax_input';
    case CustomerAdvance = 'customer_advance';
    case SupplierAdvance = 'supplier_advance';
    case AccruedExpenses = 'accrued_expenses';
    case ShippingIncome = 'shipping_income';
    case InventoryAdjustment = 'inventory_adjustment';
    case CashOverShort = 'cash_over_short';
    case Rounding = 'rounding';
    case OpeningBalance = 'opening_balance';
    case Capital = 'capital';
    case RetainedEarnings = 'retained_earnings';
    case Salaries = 'salaries';
    case OperatingExpense = 'operating_expense';
    case OtherIncome = 'other_income';
    case Other = 'other';

    public function getLabel(): string
    {
        return __('enums.account_sub_type.'.$this->value);
    }
}
