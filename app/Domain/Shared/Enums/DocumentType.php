<?php

namespace App\Domain\Shared\Enums;

use App\Support\Enums\EnumHelpers;
use Filament\Support\Contracts\HasLabel;

enum DocumentType: string implements HasLabel
{
    use EnumHelpers;

    case Sale = 'sale';
    case Draft = 'draft';
    case Quotation = 'quotation';
    case SaleReturn = 'sale_return';
    case SalesOrder = 'sales_order';
    case Purchase = 'purchase';
    case PurchaseReturn = 'purchase_return';
    case PurchaseOrder = 'purchase_order';
    case PurchaseRequisition = 'purchase_requisition';
    case StockAdjustment = 'stock_adjustment';
    case StockTransfer = 'stock_transfer';
    case StockCount = 'stock_count';
    case ReceiptVoucher = 'receipt_voucher';
    case PaymentVoucher = 'payment_voucher';
    case Expense = 'expense';
    case JournalEntry = 'journal_entry';
    case AccountTransfer = 'account_transfer';
    case LedgerDiscount = 'ledger_discount';
    case Contact = 'contact';
    case EmployeeAdvance = 'employee_advance';
    case Payroll = 'payroll';

    public function getLabel(): string
    {
        return __('enums.document_type.'.$this->value);
    }
}
