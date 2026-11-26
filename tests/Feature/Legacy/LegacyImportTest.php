<?php

use App\Domain\CashManagement\Models\CashSession;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\Variation;
use App\Domain\CRM\Enums\ContactType;
use App\Domain\CRM\Models\Contact;
use App\Domain\Finance\Enums\AccountSubType as Role;
use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Foundation\Models\Location;
use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Legacy\Import\ImportContext;
use App\Domain\Legacy\Import\LegacyImporter;
use App\Domain\Legacy\Models\LegacyImport;
use App\Domain\Legacy\Models\LegacyRecord;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleReturn;
use App\Models\User;
use Illuminate\Support\Facades\File;

/** Writes a tiny package in the format of legacy:extract. */
function legacyPackage(array $tables): string
{
    $dir = storage_path('framework/testing/legacy-'.uniqid());
    File::ensureDirectoryExists($dir);
    $manifest = ['source' => 'test.bck', 'tables' => []];
    foreach ($tables as $name => [$pk, $rows]) {
        File::put("{$dir}/{$name}.jsonl", implode("\n", array_map(fn ($r) => json_encode($r, JSON_UNESCAPED_UNICODE), $rows))."\n");
        $manifest['tables'][$name] = ['rows' => count($rows), 'columns' => array_keys($rows[0] ?? []), 'primary_key' => $pk];
    }
    File::put("{$dir}/manifest.json", json_encode($manifest));

    return $dir;
}

function legacyTables(): array
{
    $acc = fn ($id, $name, $parent, $level) => ['AccountId' => $id, 'NameA' => $name, 'NameE' => '', 'AccountIdParent' => $parent, 'AccountLevel' => $level];

    return [
        'Company' => [['CompanyId'], [['CompanyId' => '1', 'NameA' => 'روسيانو', 'NameE' => '', 'TaxNumber' => '123', 'Address' => '', 'Tel1' => '', 'Mobile' => '', 'Email' => '', 'Logo' => null]]],
        'Branch' => [['BranchId'], [['BranchId' => '1', 'NameA' => 'الياسمين', 'Tel1' => '0228', 'Address' => 'شارع 1']]],
        'Store' => [['StoreId'], [['StoreId' => '1', 'StoreCode' => '1', 'NameA' => 'مخزن الياسمين', 'BranchId' => '1', 'Tel' => '', 'Address' => '', 'Stock' => '1020050001', 'CostOfSales' => '406']]],
        'GeneralSetting' => [[], [['BranchId' => '1', 'BoxId' => '1020010001', 'BankId' => '1020020001', 'StoreId' => '1', 'Sales' => '301', 'ReturnedSales' => '405', 'Rebate' => '404', 'ProfitLoss' => '503']]],
        'FinancialYear' => [['FinancialYearId'], [['FinancialYearId' => '1', 'BeginDate' => '2023-01-01T00:00:00']]],
        'ChartOfAccount' => [['AccountId'], [
            $acc('1', 'الاصول', '0', 1), $acc('102', 'المتداولة', '1', 2), $acc('102001', 'النقدية', '102', 3), $acc('1020010001', 'خزينة الياسمين', '102001', 4),
            $acc('102002', 'البنوك', '102', 3), $acc('1020020001', 'البنك', '102002', 4), $acc('102003', 'العملاء', '102', 3),
            $acc('1020030001', 'أحمد', '102003', 4), $acc('1020030002', 'الرصيد', '102003', 4), $acc('102004', 'موظفين', '102', 3),
            $acc('1020040001', 'عصام', '102004', 4), $acc('102005', 'مخازن', '102', 3), $acc('1020050001', 'مخزن الياسمين', '102005', 4),
            $acc('2', 'الخصوم', '0', 1), $acc('201', 'المتداولة', '2', 2), $acc('201001', 'الموردين', '201', 3), $acc('2010010001', 'الزلزال', '201001', 4),
            $acc('3', 'الايرادات', '0', 1), $acc('301', 'المبيعات', '3', 2), $acc('4', 'المصروفات', '0', 1), $acc('404', 'خصم مسموح', '4', 2),
            $acc('405', 'مرتجع المبيعات', '4', 2), $acc('406', 'تكلفة المبيعات', '4', 2), $acc('5', 'حقوق الملكية', '0', 1), $acc('501', 'راس المال', '5', 2),
            $acc('501001', 'رأس المال', '501', 3), $acc('503', 'الارباح المرحلة', '5', 2),
        ]],
        'SupCusEmp' => [['Id'], [
            ['Id' => '1', 'FormType' => 51, 'Code' => '1', 'NameA' => 'أحمد', 'NameE' => '', 'Mobile' => '0100', 'Tel1' => '', 'AccountId' => '1020030001', 'POSDefaultCusCash' => false],
            ['Id' => '2', 'FormType' => 51, 'Code' => '2', 'NameA' => 'نقدي', 'NameE' => '', 'Mobile' => '', 'Tel1' => '', 'AccountId' => null, 'POSDefaultCusCash' => true],
            ['Id' => '3', 'FormType' => 50, 'Code' => '1', 'NameA' => 'الزلزال', 'NameE' => '', 'Mobile' => '', 'Tel1' => '', 'AccountId' => '2010010001', 'POSDefaultCusCash' => false],
            ['Id' => '4', 'FormType' => 52, 'Code' => '1', 'NameA' => 'عصام', 'NameE' => '', 'Mobile' => '', 'Tel1' => '', 'AccountId' => '1020040001', 'POSDefaultCusCash' => false],
        ]],
        'AccountsOpeningBalance' => [[], [
            ['BranchId' => '1', 'Serial' => '1', 'AccountId' => '501001', 'DebitLocal' => '0.00', 'CreditLocal' => '1000.00', 'Description' => ''],
            ['BranchId' => '1', 'Serial' => '2', 'AccountId' => '1020010001', 'DebitLocal' => '800.00', 'CreditLocal' => '0.00', 'Description' => ''],
            ['BranchId' => '1', 'Serial' => '3', 'AccountId' => '1020030001', 'DebitLocal' => '250.00', 'CreditLocal' => '0.00', 'Description' => ''],
            ['BranchId' => '1', 'Serial' => '4', 'AccountId' => '2010010001', 'DebitLocal' => '0.00', 'CreditLocal' => '50.00', 'Description' => ''],
        ]],
        'POS' => [[], [['BranchId' => '1', 'POSId' => '1', 'Code' => '1', 'NameA' => '1', 'Status' => 1]]],
        'Users' => [['UserId'], [['UserId' => '1', 'UserName' => 'Russ', 'UserType' => 1], ['UserId' => '2', 'UserName' => 'بسنت', 'UserType' => 2]]],
        'UserStores' => [[], [['UserId' => '2', 'StoreId' => '1', 'Checked' => true]]],
        'Unit' => [['UnitId'], [['UnitId' => '1', 'NameA' => 'عدد', 'NameE' => '']]],
        'ItemGroup' => [['GroupId'], [['GroupId' => '1', 'NameA' => 'موبايلات', 'NameE' => '', 'Code' => '1', 'GroupIdParent' => null, 'GroupLevel' => 1, 'GroupIndex' => 1]]],
        'Item' => [['ItemId'], [['ItemId' => '1960', 'GroupId' => '1', 'ItemType' => 1, 'ItemCode' => 'A-1', 'NameA' => 'شاحن', 'NameE' => '', 'Minimum' => '0', 'Reorder' => '0', 'HideItem' => false, 'Notes' => '']]],
        'ItemUnit' => [[], [['ItemId' => '1960', 'UnitId' => '1', 'MainUnit' => true, 'BarCode' => '6221000000017', 'PurchaseValue' => '40.00', 'SalesValue' => '65.00']]],
        'UsersLogin' => [[], [['UserId' => '1', 'LoginDate' => '2024-01-01T09:00:00', 'INOUT' => true], ['UserId' => '1', 'LoginDate' => '2024-01-01T09:00:00', 'INOUT' => true]]],
    ];
}

beforeEach(function () {
    seedBaseData();
    $this->actingAs(User::query()->firstOrFail());
    $this->import = fn () => app(LegacyImporter::class)->run(LegacyImport::query()->create(['source_name' => 'test', 'package_path' => legacyPackage(legacyTables())]));
});

it('imports the foundation of the old system and keeps every row in the archive', function () {
    $import = ($this->import)();

    expect($import->status)->toBe('completed')
        ->and(LegacyRecord::query()->count())->toBe(collect(legacyTables())->sum(fn ($t) => count($t[1])))
        ->and(LegacyRecord::query()->where('source_table', 'UsersLogin')->count())->toBe(2); // identical rows are both kept

    // the old chart replaces the default one, with roles on the old accounts
    expect(Account::query()->where('sub_type', Role::Cash)->value('code'))->toBe('1020010001')
        ->and(Account::query()->where('sub_type', Role::Receivable)->value('code'))->toBe('102003')
        ->and(Account::query()->where('code', '1020030001')->exists())->toBeFalse(); // a single customer's account → a contact

    expect(Contact::query()->where('name', 'أحمد')->value('balance'))->toBe('250.0000')
        ->and(Contact::query()->where('name', 'الزلزال')->value('balance'))->toBe('-50.0000')
        ->and(Contact::query()->where('type', ContactType::Employee)->where('name', 'عصام')->exists())->toBeTrue()
        ->and(Contact::query()->where('name', 'الرصيد')->exists())->toBeTrue() // account without a SupCusEmp row
        ->and(Contact::query()->where('is_default', true)->count())->toBe(1);

    expect(accountBalance(Role::Cash))->toBe(800.0)->and(accountBalance(Role::Capital))->toBe(-1000.0)
        ->and(Product::query()->where('sku', 'A-1')->firstOrFail()->defaultVariation->barcodes->pluck('barcode')->sort()->values()->all())
        ->toBe(['1960@@@', '6221000000017'])
        ->and(User::query()->where('name', 'بسنت')->firstOrFail()->allow_login)->toBeFalse()
        ->and(User::query()->where('name', 'Russ')->firstOrFail()->hasRole('manager'))->toBeTrue();
});

it('can run again without duplicating anything', function () {
    ($this->import)();
    $counts = fn () => collect(['accounts', 'contacts', 'products', 'users', 'locations', 'cash_registers', 'legacy_records', 'journal_entries', 'contact_opening_balances'])
        ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    $before = $counts();

    ($this->import)();

    expect($counts())->toBe($before);
});

it('still sees the saved map of a table after remembering into it first (a run that skips the accounts stage)', function () {
    ($this->import)();
    $context = new ImportContext(LegacyImport::query()->create(['source_name' => 'again']));

    $context->remember('ChartOfAccount', '1020030009', Contact::query()->firstOrFail()); // contacts stage runs first

    expect($context->newId('ChartOfAccount', '1020010001'))->toBe(Account::query()->where('code', '1020010001')->value('id'))
        ->and($context->newType('ChartOfAccount', '1020010001'))->toBe('account');
});

function legacyMovements(): array
{
    $inv = fn ($type, $id, $date, $sce, $net, $extra = []) => ['BranchId' => '1', 'InvoiceType' => $type, 'FYId' => '1', 'InvoiceId' => $id, 'InvoiceCode' => $id,
        'InvoiceDate' => $date, 'SCEId' => $sce, 'NetTotal' => $net, 'AdditionalDiscount' => '0.00', 'Description' => '', 'StoreId' => null, 'PTId' => '1', 'UserIdAdded' => '2', ...$extra];
    $line = fn ($type, $inv, $id, $qty, $price, $total) => ['BranchId' => '1', 'InvoiceType' => $type, 'FYId' => '1', 'InvoiceId' => $inv, 'Id' => $id, 'ItemId' => '1960',
        'Quantity' => $qty, 'Cost' => $price, 'Price' => $price, 'Total' => $total, 'TotalAfterDiscount3' => $total, 'StoreId' => '1', 'Serial' => null, 'Description' => ''];
    $pay = fn ($type, $inv, $kind, $value) => ['BranchId' => '1', 'InvoiceType' => $type, 'FYId' => '1', 'InvoiceId' => $inv, 'PaymentId' => '1', 'PaymentType' => $kind, 'PaymentValue' => $value, 'DueDate' => null];

    return [
        'Operators' => [[], [['OperatorId' => '2', 'UserId' => '2']]],
        'POSTransactions' => [[], [['BranchId' => '1', 'PTId' => '1', 'FYId' => '1', 'POSId' => '1', 'OperatorId' => '2', 'StartDate' => '2023-02-01T09:00:00', 'Status' => 1, 'FTId' => '9']]],
        'POSClosedDetails' => [[], [['BranchId' => '1', 'PTId' => '1', 'NetValue' => '130.00', 'PaymentValue' => '125.00']]],
        'Invoice' => [['BranchId', 'InvoiceType', 'FYId', 'InvoiceId'], [
            $inv(9, '1', '2023-02-01T08:00:00', '3', '400.00'),                                  // buy 10 chargers at 40, on credit
            $inv(10, '1', '2023-02-01T10:00:00', '2', '130.00'),                                 // cash sale of 2 at 65
            $inv(10, '2', '2023-02-01T11:00:00', '1', '195.00'),                                 // credit sale of 3 to أحمد
            $inv(17, '1', '2023-02-01T12:00:00', '2', '65.00', ['SourceId' => '1']),             // 1 returned from the cash sale
            $inv(12, '1', '2023-02-01T10:00:00', null, null),                                    // automatic store issue: ignored
        ]],
        'InvoiceDetails' => [[], [$line(9, '1', '1', '10', '40.00', '400.00'), $line(10, '1', '1', '2', '65.00', '130.00'),
            $line(10, '2', '1', '3', '65.00', '195.00'), $line(17, '1', '1', '1', '65.00', '65.00')]],
        'InvoicePayment' => [[], [$pay(10, '1', 5, '130.00'), $pay(10, '2', 6, '0.00'), $pay(9, '1', 6, '0.00'), $pay(17, '1', 5, '65.00')]],
        'Broker' => [[], [['BranchId' => '1', 'InvoiceType' => 10, 'FYId' => '1', 'InvoiceId' => '1', 'Id' => '1', 'BranchIdOut' => '1', 'InvoiceTypeOut' => 17, 'FYIdOut' => '1', 'InvoiceIdOut' => '1', 'IdOut' => '1']]],
        'FinancialTransactions' => [[], [
            ['BranchId' => '1', 'JVType' => 140, 'FYId' => '1', 'FTId' => '1', 'FTSerial' => '1', 'FTDate' => '2023-02-02T10:00:00', 'TotalAmount' => '100.00', 'AccountId' => '1020040001', 'VoucherHolder' => 2, 'SCEId' => '1', 'Description' => 'دفعة'],
            ['BranchId' => '1', 'JVType' => 7, 'FYId' => '1', 'FTId' => '2', 'FTSerial' => '2', 'FTDate' => '2023-02-02T11:00:00', 'TotalAmount' => '30.00', 'AccountId' => '1020040001', 'VoucherHolder' => 1, 'SCEId' => '4', 'Description' => ''],
            ['BranchId' => '1', 'JVType' => 7, 'FYId' => '1', 'FTId' => '3', 'FTSerial' => '3', 'FTDate' => '2023-02-03T11:00:00', 'TotalAmount' => '400.00', 'AccountId' => '1020010001', 'VoucherHolder' => 3, 'SCEId' => '3', 'Description' => ''],
            ['BranchId' => '1', 'JVType' => 29, 'FYId' => '1', 'FTId' => '9', 'FTSerial' => '9', 'FTDate' => '2023-02-01T20:00:00', 'TotalAmount' => '125.00', 'AccountId' => '1020010001', 'VoucherHolder' => 1, 'SCEId' => null, 'Description' => ''],
        ]],
        'FinancialTransactionsDetails' => [[], [['BranchId' => '1', 'JVType' => 7, 'FYId' => '1', 'FTId' => '2', 'Serial' => '1', 'AccountId' => '419', 'AmountLocal' => '30.00', 'Description' => 'ادوات نظافة']]],
        'BrokerPayment' => [[], [
            ['BranchId' => '1', 'InvoiceType' => 10, 'FYId' => '1', 'InvoiceId' => '2', 'PaymentId' => '1', 'PaymentValue' => '100.00', 'Source' => 140, 'SourceId' => '1', 'SourceBranchId' => null],
            ['BranchId' => '1', 'InvoiceType' => 9, 'FYId' => '1', 'InvoiceId' => '1', 'PaymentId' => '1', 'PaymentValue' => '400.00', 'Source' => 7, 'SourceId' => '3', 'SourceBranchId' => null],
        ]],
        'JournalVoucher' => [[], [
            ['BranchId' => '1', 'JVDId' => '1', 'FYId' => '1', 'JVId' => '50', 'JVSerial' => '50', 'JVDate' => '2023-02-28T12:00:00', 'Source' => 1, 'Description' => 'مرتب فبراير'],
            ['BranchId' => '1', 'JVDId' => '1', 'FYId' => '1', 'JVId' => '60', 'JVSerial' => '60', 'JVDate' => '2023-12-31T23:00:00', 'Source' => 6, 'Description' => 'اقفال'],
        ]],
        'JournalVoucherDetails' => [[], [
            ['BranchId' => '1', 'JVDId' => '1', 'FYId' => '1', 'JVId' => '50', 'Serial' => '1', 'AccountId' => '419', 'DebitLocal' => '3000.00', 'CreditLocal' => '0.00'],
            ['BranchId' => '1', 'JVDId' => '1', 'FYId' => '1', 'JVId' => '50', 'Serial' => '2', 'AccountId' => '1020040001', 'DebitLocal' => '0.00', 'CreditLocal' => '3000.00'],
        ]],
    ];
}

it('replays sales, returns, purchases, vouchers and salary entries with the right balances and stock', function () {
    $tables = legacyTables();
    $tables['ChartOfAccount'][1][] = ['AccountId' => '419', 'NameA' => 'مصروفات محل', 'NameE' => '', 'AccountIdParent' => '4', 'AccountLevel' => 2];
    $import = app(LegacyImporter::class)->run(LegacyImport::query()->create(['source_name' => 'test', 'package_path' => legacyPackage([...$tables, ...legacyMovements()])]));
    expect($import->status)->toBe('completed');

    $ahmed = Contact::query()->where('name', 'أحمد')->firstOrFail();
    $supplier = Contact::query()->where('name', 'الزلزال')->firstOrFail();
    $employee = Contact::query()->where('name', 'عصام')->firstOrFail();
    $credit = Sale::query()->where('invoice_no', 'INV1-23-2')->firstOrFail();

    expect(Sale::query()->where('status', 'final')->count())->toBe(2)
        ->and($credit->payment_status->value)->toBe('partial')            // credit sale, 100 collected later by a receipt voucher
        ->and($credit->due_total)->toBe('95.0000')
        ->and($ahmed->balance)->toBe('345.0000')                          // 250 opening + 195 credit sale − 100 receipt
        ->and($supplier->balance)->toBe('-50.0000')                       // −50 opening − 400 purchase + 400 paid
        ->and($employee->balance)->toBe('-2930.0000')                     // −3000 salary due + 100 he collected − 30 he paid (his account was the vouchers' money side)
        ->and(SaleReturn::query()->firstOrFail()->sale_id)->not->toBeNull()
        ->and((string) app(StockLedger::class)->onHand(
            Variation::query()->where('sku', 'A-1')->value('id'), Location::query()->value('id')))
        ->toBe('6.0000');                                                 // 10 bought − 2 − 3 sold + 1 returned

    expect(Expense::query()->count())->toBe(1)
        ->and(ExpenseCategory::query()->where('name', 'مصروفات محل')->exists())->toBeTrue()
        // the shift keeps the old figures; the money itself moves with the old closing voucher, so nothing posts twice
        ->and(CashSession::query()->firstOrFail()->closing_note)->toContain('130.00')->toContain('125.00')
        ->and(accountBalance(Role::CashOverShort))->toBe(0.0);

    assertStockConsistent();
});
