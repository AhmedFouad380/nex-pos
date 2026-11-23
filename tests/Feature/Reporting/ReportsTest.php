<?php

use App\Domain\Catalog\Models\TaxRate;
use App\Domain\Finance\Actions\CreateExpense;
use App\Domain\Finance\Actions\RecordPayment;
use App\Domain\Finance\Data\PaymentData;
use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Foundation\Models\Location;
use App\Domain\Reporting\Data\ReportFilter;
use App\Domain\Reporting\Services\FinancialStatements;
use App\Domain\Reporting\Services\OperationsReports;
use App\Domain\Reporting\Services\SalesReport;
use App\Domain\Sales\Actions\CreateSaleReturn;
use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Pages\Reports\AgingReportPage;
use App\Filament\Admin\Pages\Reports\BalanceSheetPage;
use App\Filament\Admin\Pages\Reports\ExpensesReportPage;
use App\Filament\Admin\Pages\Reports\MoneyMovementPage;
use App\Filament\Admin\Pages\Reports\ProfitAndLossPage;
use App\Filament\Admin\Pages\Reports\PurchasesReportPage;
use App\Filament\Admin\Pages\Reports\SalesReportPage;
use App\Filament\Admin\Pages\Reports\StockReportPage;
use App\Filament\Admin\Pages\Reports\TaxReportPage;
use App\Filament\Admin\Widgets\BranchPerformance;
use App\Filament\Admin\Widgets\BusinessOverview;
use App\Filament\Admin\Widgets\LowStock;
use App\Filament\Admin\Widgets\OverdueCustomers;
use App\Filament\Admin\Widgets\SalesTrendChart;
use App\Filament\Admin\Widgets\TopProducts;
use App\Models\User;
use App\Support\FiscalYear;
use Filament\Facades\Filament;

use function Pest\Livewire\livewire;

beforeEach(function () {
    seedBaseData();
    $this->actingAs(User::query()->firstOrFail());
    Filament::setCurrentPanel('admin');
    $this->location = Location::query()->firstOrFail();
    $this->tea = makeVariation(['alert_qty' => 9], ['purchase_price' => 6, 'sell_price' => 10, 'sell_price_inc_tax' => 10]);
    $this->supplier = makeSupplier();
    makePurchase($this->supplier, [[$this->tea, 10, 6]]);
    $this->session = openSession();
    // 3 sold for cash, 1 of them returned to stock; 2 sold on credit
    $sale = sell([[$this->tea, 3]], [cash(30)], ['cashSessionId' => $this->session->id]);
    app(CreateSaleReturn::class)->handle($sale, [['sale_item_id' => $sale->items()->value('id'), 'qty' => 1]], cashMethodId(), $this->session->id);
    $this->customer = makeCustomer();
    sell([[$this->tea, 2]], [], ['cashSessionId' => $this->session->id, 'customerId' => $this->customer->id]);
    app(CreateExpense::class)->handle(['location_id' => $this->location->id, 'amount' => 100, 'tax_rate_id' => TaxRate::query()->where('rate', 14)->value('id')],
        ['payment_method_id' => cashMethodId()]);
    $this->filter = new ReportFilter(now()->startOfMonth(), now());
});

it('computes sales, returns and gross profit by any dimension', function () {
    $summary = app(SalesReport::class)->summary($this->filter);
    expect($summary)->toMatchArray(['invoices' => 2, 'sales' => '50.00', 'returns' => '10.00', 'net' => '40.00', 'cost' => '24.00', 'profit' => '16.00', 'margin' => 40.0]);

    $byProduct = app(SalesReport::class)->by('product', $this->filter);
    expect($byProduct)->toHaveCount(1)->and($byProduct[0])->toMatchArray(['qty' => '4.0000', 'net' => '40.00', 'profit' => '16.00']);

    $byCustomer = app(SalesReport::class)->by('customer', $this->filter)->keyBy('label');
    expect($byCustomer[$this->customer->name]['net'])->toBe('20.00');

    foreach (SalesReport::DIMENSIONS as $dimension) {
        expect(app(SalesReport::class)->by($dimension, $this->filter)->sum(fn ($r) => (float) $r['net']))->toEqual(40.0);
    }
});

it('ages only the account balance: payments not linked to an invoice pay off the oldest first', function () {
    $old = sell([[$this->tea, 2]], [], ['cashSessionId' => $this->session->id, 'customerId' => $this->customer->id]);
    $old->update(['sale_date' => now()->subDays(120)]); // 20 owed for 120 days + 20 owed today
    app(RecordPayment::class)->handle(new PaymentData(PaymentDirection::In, cashMethodId(), '20', $this->customer->id, $this->location->id));

    $row = app(OperationsReports::class)->aging('receivable')[0];
    expect($row)->toMatchArray(['current' => '20.00', 'b90' => '0.00', 'total' => '20.00', 'balance' => '20.00']);

    app(RecordPayment::class)->handle(new PaymentData(PaymentDirection::In, cashMethodId(), '20', $this->customer->id, $this->location->id));
    expect(app(OperationsReports::class)->aging('receivable'))->toBeEmpty(); // settled: not listed even though invoices are open
});

it('agrees with the journal in the income statement and balances the balance sheet', function () {
    $pl = app(FinancialStatements::class)->incomeStatement(now()->startOfMonth(), now());
    expect($pl)->toMatchArray(['net_sales' => '40.00', 'cost_of_sales' => '24.00', 'gross_profit' => '16.00', 'net_profit' => '-84.00']); // 16 − 100 expense

    $bs = app(FinancialStatements::class)->balanceSheet(now());
    expect($bs['balanced'])->toBeTrue()->and($bs['earnings'])->toBe('-84.00');
});

it('reports purchases, stock, tax, aging, expenses and money movement', function () {
    $reports = app(OperationsReports::class);

    expect($reports->purchases('supplier', $this->filter)[0])->toMatchArray(['amount' => '60.00', 'due' => '60.00'])
        ->and($reports->purchases('product', $this->filter)[0])->toMatchArray(['qty' => '10.0000', 'average' => '6.00']);

    $stock = $reports->stock(null);
    expect($stock[0])->toMatchArray(['qty' => '6.0000', 'value' => '36.00', 'retail' => '60.00', 'low' => true])
        ->and($reports->stock(null, null, true))->toHaveCount(1);

    expect($reports->tax($this->filter))->toMatchArray(['output' => '0.00', 'input' => '14.00', 'net' => '-14.00']);

    $aging = $reports->aging('receivable');
    expect($aging)->toHaveCount(1)->and($aging[0])->toMatchArray(['label' => $this->customer->name, 'current' => '20.00', 'total' => '20.00'])
        ->and($reports->aging('payable')[0]['total'])->toBe('60.00');

    expect($reports->expenses('category', $this->filter)[0])->toMatchArray(['amount' => '100.00', 'tax' => '14.00', 'total' => '114.00']);

    $money = $reports->moneyMovement($this->filter);
    expect($money['accounts'][0])->toMatchArray(['in' => '30.00', 'out' => '124.00', 'closing' => '-94.00']); // 30 in; 10 refund + 114 expense out
});

it('opens every report, switches grouping and exports to Excel', function () {
    foreach ([ProfitAndLossPage::class, BalanceSheetPage::class, PurchasesReportPage::class, StockReportPage::class,
        TaxReportPage::class, AgingReportPage::class, ExpensesReportPage::class, MoneyMovementPage::class] as $page) {
        livewire($page)->assertOk();
    }

    $sales = livewire(SalesReportPage::class)->assertOk()->assertSee('40.00');
    foreach (array_keys($sales->instance()->groupOptions()) as $group) {
        $sales->set('group', $group)->assertOk();
    }
    $sales->set('group', 'product')->assertSee($this->tea->fullName());

    livewire(StockReportPage::class)->set('flag', true)->assertSee($this->tea->fullName());
    livewire(AgingReportPage::class)->assertSee($this->customer->name)->set('group', 'payable')->assertSee($this->supplier->name);

    livewire(SalesReportPage::class)->callAction('export')->assertFileDownloaded();
});

it('shows the dashboard with the widgets each role may see', function () {
    $this->get(Dashboard::getUrl())->assertOk();
    // the current year by default, then a month and a day
    livewire(BusinessOverview::class)->assertOk()->assertSee(__('Year :year', ['year' => now()->year]))->assertSee(__('Gross profit'));
    livewire(BusinessOverview::class, ['pageFilters' => ['period' => 'month', 'year' => now()->year, 'month' => now()->month]])
        ->assertOk()->assertSee(now()->translatedFormat('F Y'));
    livewire(BusinessOverview::class, ['pageFilters' => ['period' => 'day', 'day' => now()->toDateString()]])->assertOk()->assertSee(now()->format('Y-m-d'));
    livewire(BusinessOverview::class, ['pageFilters' => ['period' => 'year', 'year' => now()->year - 1]])->assertOk()->assertSee(__('Year :year', ['year' => now()->year - 1]));
    livewire(SalesTrendChart::class, ['pageFilters' => ['period' => 'month', 'year' => now()->year, 'month' => now()->month]])->assertOk();
    livewire(SalesTrendChart::class, ['pageFilters' => ['period' => 'day', 'day' => now()->toDateString()]])->assertOk();
    // the chart's own filters: one branch, grouped by month
    $branch = Location::query()->firstOrFail();
    livewire(SalesTrendChart::class)->set('filters', ['location' => $branch->id, 'group' => 'month'])->assertOk()->assertSee($branch->name);
    livewire(SalesTrendChart::class)->set('filters', ['location' => null, 'group' => 'day'])->assertOk();
    livewire(SalesTrendChart::class)->assertOk();
    livewire(TopProducts::class)->assertOk()->assertSee($this->tea->fullName());
    livewire(LowStock::class)->assertOk()->assertSee($this->tea->fullName());
    livewire(OverdueCustomers::class)->assertOk()->assertSee($this->customer->name);

    $store = User::factory()->create();
    $store->assignRole('storekeeper');
    $this->flushSession(); // a new login, not the admin's session
    $this->actingAs($store);
    expect(BusinessOverview::canView())->toBeFalse()->and(LowStock::canView())->toBeTrue();
    $this->get(Dashboard::getUrl())->assertOk();
    $this->get(SalesReportPage::getUrl())->assertForbidden();
    $this->get(StockReportPage::getUrl())->assertOk();
});

it('keeps the dashboard year and the top-bar year the same', function () {
    $last = now()->year - 1;
    session()->put(FiscalYear::SESSION_KEY, $last); // picked in the top-bar switcher

    livewire(Dashboard::class)->assertSet('filters.year', $last);
    expect(BranchPerformance::canView())->toBeFalse(); // "today" has no place in a past year

    // picked in the dashboard filter: the whole app follows
    livewire(Dashboard::class)->set('filters.year', (string) now()->year)->assertRedirect(Dashboard::getUrl());
    expect(FiscalYear::selected())->toBe(now()->year)->and(BranchPerformance::canView())->toBeTrue();
    livewire(Dashboard::class)->assertSet('filters.year', now()->year);
});
