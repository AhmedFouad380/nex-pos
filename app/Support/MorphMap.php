<?php

namespace App\Support;

use App\Domain\CashManagement\Models\CashDenomination;
use App\Domain\CashManagement\Models\CashMovement;
use App\Domain\CashManagement\Models\CashRegister;
use App\Domain\CashManagement\Models\CashSession;
use App\Domain\Catalog\Models\Brand;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\ComboItem;
use App\Domain\Catalog\Models\Discount;
use App\Domain\Catalog\Models\PriceGroup;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductAttribute;
use App\Domain\Catalog\Models\TaxRate;
use App\Domain\Catalog\Models\Unit;
use App\Domain\Catalog\Models\Variation;
use App\Domain\Catalog\Models\VariationBarcode;
use App\Domain\Catalog\Models\VariationPrice;
use App\Domain\Catalog\Models\VariationTemplate;
use App\Domain\Catalog\Models\VariationTemplateValue;
use App\Domain\Catalog\Models\Warranty;
use App\Domain\CRM\Models\Contact;
use App\Domain\CRM\Models\ContactOpeningBalance;
use App\Domain\CRM\Models\CustomerGroup;
use App\Domain\CRM\Models\LedgerDiscount;
use App\Domain\CRM\Models\LoyaltyTransaction;
use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\AccountTransfer;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Models\JournalEntry;
use App\Domain\Finance\Models\JournalLine;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Models\PaymentMethod;
use App\Domain\Foundation\Models\Currency;
use App\Domain\Foundation\Models\CustomFieldDefinition;
use App\Domain\Foundation\Models\DocumentSequence;
use App\Domain\Foundation\Models\Location;
use App\Domain\Foundation\Models\Note;
use App\Domain\Inventory\Models\Lot;
use App\Domain\Inventory\Models\Serial;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\StockAdjustmentItem;
use App\Domain\Inventory\Models\StockCount;
use App\Domain\Inventory\Models\StockCountItem;
use App\Domain\Inventory\Models\StockLayer;
use App\Domain\Inventory\Models\StockLayerConsumption;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Models\StockTransferItem;
use App\Domain\Legacy\Models\LegacyIdMap;
use App\Domain\Legacy\Models\LegacyImport;
use App\Domain\Legacy\Models\LegacyRecord;
use App\Domain\Payroll\Models\AdvanceRequest;
use App\Domain\Payroll\Models\EmployeeAdvance;
use App\Domain\Payroll\Models\EmployeeAdvanceSettlement;
use App\Domain\Payroll\Models\EmployeeProfile;
use App\Domain\Payroll\Models\PayrollLine;
use App\Domain\Payroll\Models\PayrollRun;
use App\Domain\Printing\Models\InvoiceLayout;
use App\Domain\Printing\Models\LabelTemplate;
use App\Domain\Printing\Models\NotificationTemplate;
use App\Domain\Printing\Models\Printer;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Purchasing\Models\PurchaseCost;
use App\Domain\Purchasing\Models\PurchaseItem;
use App\Domain\Purchasing\Models\PurchaseOrder;
use App\Domain\Purchasing\Models\PurchaseOrderItem;
use App\Domain\Purchasing\Models\PurchaseRequisition;
use App\Domain\Purchasing\Models\PurchaseRequisitionItem;
use App\Domain\Purchasing\Models\PurchaseReturn;
use App\Domain\Purchasing\Models\PurchaseReturnItem;
use App\Domain\Restaurant\Models\Booking;
use App\Domain\Restaurant\Models\KitchenStation;
use App\Domain\Restaurant\Models\RestaurantTable;
use App\Domain\Sales\Models\RecurringProfile;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleItem;
use App\Domain\Sales\Models\SaleReturn;
use App\Domain\Sales\Models\SaleReturnItem;
use App\Domain\Sales\Models\SalesOrder;
use App\Domain\Sales\Models\SalesOrderItem;
use App\Domain\Sales\Models\ServiceType;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletDevice;
use App\Domain\Wallet\Models\WalletMessageTemplate;
use App\Domain\Wallet\Models\WalletTransaction;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Short, stable aliases stored in *_type morph columns.
 * Generated alongside the models — keep in sync when adding morphable models.
 */
final class MorphMap
{
    /** @return array<string, class-string> */
    public static function map(): array
    {
        return [
            'user' => User::class,
            'account_transfer' => AccountTransfer::class,
            'account' => Account::class,
            'booking' => Booking::class,
            'brand' => Brand::class,
            'cash_denomination' => CashDenomination::class,
            'cash_movement' => CashMovement::class,
            'cash_register' => CashRegister::class,
            'cash_session' => CashSession::class,
            'category' => Category::class,
            'combo_item' => ComboItem::class,
            'contact_opening_balance' => ContactOpeningBalance::class,
            'contact' => Contact::class,
            'currency' => Currency::class,
            'custom_field_definition' => CustomFieldDefinition::class,
            'customer_group' => CustomerGroup::class,
            'discount' => Discount::class,
            'document_sequence' => DocumentSequence::class,
            'expense_category' => ExpenseCategory::class,
            'expense' => Expense::class,
            'employee_advance' => EmployeeAdvance::class,
            'advance_request' => AdvanceRequest::class,
            'employee_advance_settlement' => EmployeeAdvanceSettlement::class,
            'employee_profile' => EmployeeProfile::class,
            'payroll_line' => PayrollLine::class,
            'payroll' => PayrollRun::class,
            'invoice_layout' => InvoiceLayout::class,
            'journal_entry' => JournalEntry::class,
            'journal_line' => JournalLine::class,
            'kitchen_station' => KitchenStation::class,
            'label_template' => LabelTemplate::class,
            'ledger_discount' => LedgerDiscount::class,
            'legacy_id_map' => LegacyIdMap::class,
            'legacy_import' => LegacyImport::class,
            'legacy_record' => LegacyRecord::class,
            'location' => Location::class,
            'lot' => Lot::class,
            'loyalty_transaction' => LoyaltyTransaction::class,
            'note' => Note::class,
            'notification_template' => NotificationTemplate::class,
            'payment_allocation' => PaymentAllocation::class,
            'payment_method' => PaymentMethod::class,
            'payment' => Payment::class,
            'price_group' => PriceGroup::class,
            'printer' => Printer::class,
            'product_attribute' => ProductAttribute::class,
            'product' => Product::class,
            'purchase_cost' => PurchaseCost::class,
            'purchase_item' => PurchaseItem::class,
            'purchase_order_item' => PurchaseOrderItem::class,
            'purchase_order' => PurchaseOrder::class,
            'purchase_requisition_item' => PurchaseRequisitionItem::class,
            'purchase_requisition' => PurchaseRequisition::class,
            'purchase_return_item' => PurchaseReturnItem::class,
            'purchase_return' => PurchaseReturn::class,
            'purchase' => Purchase::class,
            'recurring_profile' => RecurringProfile::class,
            'restaurant_table' => RestaurantTable::class,
            'sale_item' => SaleItem::class,
            'sale_return_item' => SaleReturnItem::class,
            'sale_return' => SaleReturn::class,
            'sale' => Sale::class,
            'sales_order_item' => SalesOrderItem::class,
            'sales_order' => SalesOrder::class,
            'serial' => Serial::class,
            'service_type' => ServiceType::class,
            'stock_adjustment_item' => StockAdjustmentItem::class,
            'stock_adjustment' => StockAdjustment::class,
            'stock_count_item' => StockCountItem::class,
            'stock_count' => StockCount::class,
            'stock_layer_consumption' => StockLayerConsumption::class,
            'stock_layer' => StockLayer::class,
            'stock_level' => StockLevel::class,
            'stock_movement' => StockMovement::class,
            'stock_transfer_item' => StockTransferItem::class,
            'stock_transfer' => StockTransfer::class,
            'tax_rate' => TaxRate::class,
            'unit' => Unit::class,
            'variation_barcode' => VariationBarcode::class,
            'variation_price' => VariationPrice::class,
            'variation_template_value' => VariationTemplateValue::class,
            'variation_template' => VariationTemplate::class,
            'variation' => Variation::class,
            'warranty' => Warranty::class,
            'wallet' => Wallet::class,
            'wallet_device' => WalletDevice::class,
            'wallet_message_template' => WalletMessageTemplate::class,
            'wallet_transaction' => WalletTransaction::class,
            'role' => Role::class,
            'permission' => Permission::class,
            'media' => Media::class,
            'activity' => Activity::class,
        ];
    }
}
