<?php

namespace App\Domain\Legacy\Import\Stages;

use App\Domain\Catalog\Models\Variation;
use App\Domain\CRM\Enums\ContactType;
use App\Domain\CRM\Models\Contact;
use App\Domain\CRM\Services\ContactLedger;
use App\Domain\Finance\Actions\RecordPayment;
use App\Domain\Finance\Data\PaymentData;
use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Finance\Enums\PaymentMethodType;
use App\Domain\Finance\Models\PaymentMethod;
use App\Domain\Finance\Services\Posting;
use App\Domain\Foundation\Models\Location;
use App\Domain\Inventory\Enums\SerialStatus;
use App\Domain\Inventory\Enums\StockAdjustmentType;
use App\Domain\Inventory\Enums\StockMovementType;
use App\Domain\Inventory\Enums\StockTransferStatus;
use App\Domain\Inventory\Models\Serial;
use App\Domain\Inventory\Models\StockAdjustment;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Services\StockLedger;
use App\Domain\Legacy\Import\ImportContext;
use App\Domain\Legacy\Import\LegacyPackage;
use App\Domain\Legacy\Import\Stage;
use App\Domain\Purchasing\Enums\PurchaseStatus;
use App\Domain\Purchasing\Models\Purchase;
use App\Domain\Purchasing\Models\PurchaseItem;
use App\Domain\Purchasing\Models\PurchaseReturn;
use App\Domain\Sales\Enums\SaleChannel;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Sales\Models\SaleItem;
use App\Domain\Sales\Models\SaleReturn;
use App\Domain\Shared\Enums\PaymentStatus;
use App\Domain\Shared\Services\PaymentStatusRefresher;
use App\Support\Decimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every stock document of the old system, replayed in date order through the stock ledger (so FIFO cost,
 * stock levels and the journal come out right):
 *   8 opening stock · 9 purchase · 19 stock count · 20 transfer · 18 write-off · 10 sale · 17 sale return
 *   16 purchase return · 38 quotation (no stock).
 * Store issues / receipts (12 / 11) are the old system's automatic copies of these documents — the stock moves
 * once, from the document itself. Cash paid on an invoice (payment type 5) becomes a payment allocated to it;
 * credit (type 6) is no payment: the invoice stays due.
 *
 * Numbers: <prefix><branch>-<year>-<old code>, e.g. INV1-24-153, so the old numbers can still be searched.
 */
final class DocumentsStage implements Stage
{
    /** old type => [order on the same timestamp, handler] */
    private const TYPES = [8 => [0, 'opening'], 9 => [1, 'purchase'], 19 => [2, 'count'], 20 => [3, 'transfer'], 18 => [4, 'writeOff'],
        10 => [5, 'sale'], 17 => [6, 'saleReturn'], 16 => [7, 'purchaseReturn'], 38 => [8, 'quotation']];

    private const CHUNK = 250;

    private LegacyPackage $package;

    private ImportContext $context;

    /** @var array<string, list<array<string, mixed>>> invoice key => lines */
    private array $lines = [];

    /** @var array<string, list<array<string, mixed>>> invoice key => cash payments */
    private array $payments = [];

    /** @var array<string, array{0: string, 1: string}> return line key => [original invoice key, original line id] */
    private array $returnLinks = [];

    /** @var array<int, Variation> */
    private array $variations = [];

    private ?int $cashMethod = null;

    private ?int $unknownSupplier = null;

    public function __construct(private readonly StockLedger $stock, private readonly Posting $posting) {}

    public function name(): string
    {
        return 'documents';
    }

    public static function invoiceKey(array $r): string
    {
        return ImportContext::key($r['BranchId'], $r['InvoiceType'], $r['FYId'], $r['InvoiceId']);
    }

    public function run(LegacyPackage $package, ImportContext $context): void
    {
        $this->package = $package;
        $this->context = $context;
        $this->cashMethod = PaymentMethod::query()->where('type', PaymentMethodType::Cash)->value('id');
        $this->load();
        $events = $this->events();
        $total = count($events);
        $done = 0;

        ContactLedger::deferring(function () use ($events, $total, &$done) {
            foreach (array_chunk($events, self::CHUNK) as $chunk) {
                DB::transaction(function () use ($chunk) {
                    foreach ($chunk as [$table, $key, $type, $row]) { // [4] is the sort date
                        if ($this->context->newId($table, $key)) {
                            continue; // already imported
                        }
                        $handler = self::TYPES[$type][1];
                        $this->{$handler}($row, $key);
                    }
                });
                $done += count($chunk);
                $this->context->progress($this->name(), $done, $total);
            }
        });
    }

    private function load(): void
    {
        $wanted = array_keys(self::TYPES);
        foreach ($this->package->rows('InvoiceDetails') as $l) {
            if (in_array((int) $l['InvoiceType'], $wanted, true)) {
                $this->lines[self::invoiceKey($l)][] = array_intersect_key($l, array_flip(['Id', 'ItemId', 'Quantity', 'Cost', 'Price', 'Total', 'TotalAfterDiscount3', 'StoreId', 'Serial', 'Description']));
            }
        }
        foreach ($this->package->rows('InvoicePayment') as $p) {
            if ((int) $p['PaymentType'] === 5 && Decimal::of($p['PaymentValue'] ?? 0)->isPositive()) {
                $this->payments[self::invoiceKey($p)][] = ['amount' => $p['PaymentValue'], 'account' => (string) ($p['AccountId'] ?? '')];
            }
        }
        foreach ($this->package->rows('Broker') as $b) {
            $out = ImportContext::key($b['BranchIdOut'] ?? $b['BranchId'], $b['InvoiceTypeOut'], $b['FYIdOut'], $b['InvoiceIdOut'], $b['IdOut']);
            $this->returnLinks[$out] = [ImportContext::key($b['BranchId'], $b['InvoiceType'], $b['FYId'], $b['InvoiceId']), (string) $b['Id']];
        }
        foreach (Variation::query()->with('product')->get() as $v) {
            $this->variations[$v->id] = $v;
        }
    }

    /** Header columns the handlers read — the old Invoice table has ~80, keeping all of them for ~50k headers costs too much memory. */
    private const HEADER = ['BranchId', 'InvoiceType', 'FYId', 'InvoiceId', 'InvoiceCode', 'InvoiceDate', 'SCEId', 'NetTotal', 'AdditionalDiscount',
        'Description', 'StoreId', 'PTId', 'UserIdAdded', 'SupCustInvNo', 'SourceId'];

    /** @return list<array{0: string, 1: string, 2: int, 3: array<string, mixed>, 4: string}> [map table, key, type, row, date] sorted by date */
    private function events(): array
    {
        $keep = array_flip(self::HEADER);
        $events = [];
        foreach ($this->package->rows('Invoice') as $row) {
            $type = (int) $row['InvoiceType'];
            if (isset(self::TYPES[$type]) && $type !== 20) {
                $events[] = ['Invoice', self::invoiceKey($row), $type, array_intersect_key($row, $keep), (string) $row['InvoiceDate']];
            }
        }
        // lines whose header is gone (the old system lost it while editing): take the header's last saved version
        $headers = array_flip(array_column($events, 1));
        $orphans = array_diff_key($this->lines, $headers);
        if ($orphans !== []) {
            $history = [];
            foreach ($this->package->rows('InvoiceHistory') as $row) {
                $key = self::invoiceKey($row);
                if (isset($orphans[$key]) && (int) $row['HistoryId'] >= (int) ($history[$key]['HistoryId'] ?? 0)) {
                    $history[$key] = $row;
                }
            }
            foreach ($history as $key => $row) {
                $this->context->warn(__('legacy.warnings.recovered_header', ['doc' => $key, 'lines' => count($orphans[$key])]));
                $events[] = ['Invoice', $key, (int) $row['InvoiceType'], array_intersect_key($row, $keep), (string) $row['InvoiceDate']];
            }
        }
        foreach ($this->package->rows('GoodTransfer') as $row) {
            $events[] = ['GoodTransfer', ImportContext::key($row['BranchId'], $row['FYId'], $row['GTId']), 20, $row, (string) $row['GTDate']];
        }
        usort($events, fn ($a, $b) => [$a[4], self::TYPES[$a[2]][0], $a[1]] <=> [$b[4], self::TYPES[$b[2]][0], $b[1]]);

        return $events;
    }

    // ------------------------------------------------------------------ documents

    private function sale(array $h, string $key): void
    {
        $lines = $this->lines[$key] ?? [];
        $location = $this->documentLocation($h);
        $date = Carbon::parse($h['InvoiceDate']);
        $sale = Sale::query()->create([
            'public_token' => (string) Str::ulid(),
            'invoice_no' => $this->number('INV', $h),
            'status' => SaleStatus::Final,
            'channel' => SaleChannel::Import,
            'location_id' => $location,
            'customer_id' => $this->contact($h['SCEId'], ContactType::Customer),
            'cash_session_id' => $this->context->newId('POSTransactions', ImportContext::key($h['BranchId'], $h['PTId'] ?? '')),
            'sale_date' => $date,
            'note' => trim((string) ($h['Description'] ?? '')) ?: null,
            'created_by' => $this->context->newId('Users', (string) ($h['UserIdAdded'] ?? '')),
        ]);
        $subtotal = BigDecimal::zero();
        $cost = BigDecimal::zero();
        foreach ($lines as $line) {
            $variation = $this->variation($line['ItemId'], $key);
            $qty = Decimal::of($line['Quantity']);
            if (! $variation || ! $qty->isPositive()) {
                continue;
            }
            [$before, $unit, $total] = $this->prices($line, $qty);
            $item = SaleItem::query()->create([
                'sale_id' => $sale->id, 'product_id' => $variation->product_id, 'variation_id' => $variation->id, 'unit_id' => $variation->product->unit_id,
                'unit_multiplier' => 1, 'qty' => Decimal::toDb($qty), 'base_qty' => Decimal::toDb($qty),
                'unit_price_before_discount' => Decimal::toDb($before), 'discount_type' => $before->isGreaterThan($unit) ? 'fixed' : null,
                'discount_value' => Decimal::toDb(BigDecimal::max($before->minus($unit), BigDecimal::zero())),
                'unit_price' => Decimal::toDb($unit), 'unit_price_inc_tax' => Decimal::toDb($unit), 'tax_amount' => 0, 'line_total' => Decimal::toDb($total),
                'note' => $this->serialNote($line),
            ]);
            if ($variation->product->tracksStock()) {
                $issue = $this->stock->issue($variation, $this->location($line['StoreId'] ?? null, $h['BranchId']), $qty, StockMovementType::Sale, $item, at: $date, allowNegative: true);
                $item->update(['unit_cost' => Decimal::toDb($issue->unitCost), 'cost_total' => Decimal::toDb($issue->totalCost)]);
                $cost = $cost->plus($issue->totalCost);
            }
            $this->serial($line, $variation, $location, sold: $item);
            $this->context->remember('InvoiceDetails', ImportContext::key($key, $line['Id']), $item);
            $subtotal = $subtotal->plus($total);
        }
        $this->totals($sale, $h, $subtotal, ['cost_total' => Decimal::toDb($cost)]);
        $this->pay($sale, $key, PaymentDirection::In, $sale->customer_id, $location, $date, $sale->cash_session_id);
        $this->finish($sale, 'Invoice', $key, 'sales');
    }

    private function saleReturn(array $h, string $key): void
    {
        $lines = $this->lines[$key] ?? [];
        $location = $this->documentLocation($h);
        $date = Carbon::parse($h['InvoiceDate']);
        $saleId = null;
        foreach ($lines as $line) {
            [$origKey] = $this->returnLinks[ImportContext::key($key, $line['Id'])] ?? [null];
            $saleId ??= $origKey ? $this->context->newId('Invoice', $origKey) : null;
        }
        $sale = $saleId ? Sale::query()->find($saleId) : null;
        $return = SaleReturn::query()->create([
            'number' => $this->number('SR', $h),
            'sale_id' => $sale?->id,
            'customer_id' => $sale->customer_id ?? $this->contact($h['SCEId'], ContactType::Customer),
            'location_id' => $location,
            'cash_session_id' => $this->context->newId('POSTransactions', ImportContext::key($h['BranchId'], $h['PTId'] ?? '')),
            'return_date' => $date,
            'reason' => trim((string) ($h['Description'] ?? '')) ?: null,
            'created_by' => $this->context->newId('Users', (string) ($h['UserIdAdded'] ?? '')),
        ]);
        $subtotal = BigDecimal::zero();
        $cost = BigDecimal::zero();
        foreach ($lines as $line) {
            $variation = $this->variation($line['ItemId'], $key);
            $qty = Decimal::of($line['Quantity']);
            if (! $variation || ! $qty->isPositive()) {
                continue;
            }
            [, $unit, $total] = $this->prices($line, $qty);
            [$origKey, $origLine] = $this->returnLinks[ImportContext::key($key, $line['Id'])] ?? [null, null];
            $saleItem = $origKey ? SaleItem::query()->find($this->context->newId('InvoiceDetails', ImportContext::key($origKey, $origLine))) : null;
            $unitCost = $saleItem && Decimal::of($saleItem->qty)->isPositive()
                ? Decimal::divide($saleItem->cost_total, $saleItem->qty) : Decimal::of($line['Cost'] ?? $variation->purchase_price);
            $item = $return->items()->create([
                'sale_item_id' => $saleItem?->id, 'variation_id' => $variation->id, 'unit_id' => $variation->product->unit_id, 'unit_multiplier' => 1,
                'qty' => Decimal::toDb($qty), 'base_qty' => Decimal::toDb($qty), 'unit_price' => Decimal::toDb($unit), 'tax_amount' => 0,
                'line_total' => Decimal::toDb($total), 'unit_cost' => Decimal::toDb($unitCost), 'restock' => $variation->product->tracksStock(),
            ]);
            if ($variation->product->tracksStock()) {
                $this->stock->receive($variation, $location, $qty, $unitCost, StockMovementType::SaleReturn, $item, at: $date);
                $cost = $cost->plus($unitCost->multipliedBy($qty));
            }
            $saleItem?->update(['qty_returned' => Decimal::toDb(Decimal::of($saleItem->qty_returned)->plus($qty))]);
            $this->serial($line, $variation, $location, returned: true);
            $subtotal = $subtotal->plus($total);
        }
        $net = Decimal::of($h['NetTotal'] ?? $subtotal);
        $return->update(['subtotal' => Decimal::toDb($subtotal), 'discount_amount' => Decimal::toDb($subtotal->minus($net)), 'tax_amount' => 0,
            'total' => Decimal::toDb($net), 'due_total' => Decimal::toDb($net), 'payment_status' => PaymentStatus::Due, 'cost_total' => Decimal::toDb($cost)]);
        $this->pay($return, $key, PaymentDirection::Out, $return->customer_id, $location, $date, $return->cash_session_id);
        $this->finish($return, 'Invoice', $key, 'sale returns');
    }

    private function purchase(array $h, string $key): void
    {
        $lines = $this->lines[$key] ?? [];
        $location = $this->documentLocation($h);
        $date = Carbon::parse($h['InvoiceDate']);
        $purchase = Purchase::query()->create([
            'number' => $this->number('PU', $h),
            'supplier_ref' => trim((string) ($h['SupCustInvNo'] ?? '')) ?: null,
            'supplier_id' => $this->contact($h['SCEId'], ContactType::Supplier),
            'location_id' => $location,
            'purchase_date' => $date,
            'status' => PurchaseStatus::Received,
            'exchange_rate' => 1,
            'note' => trim((string) ($h['Description'] ?? '')) ?: null,
            'created_by' => $this->context->newId('Users', (string) ($h['UserIdAdded'] ?? '')),
        ]);
        $subtotal = collect($lines)->reduce(fn (BigDecimal $c, $l) => $c->plus(Decimal::of($l['TotalAfterDiscount3'] ?? $l['Total'] ?? 0)), BigDecimal::zero());
        $additional = Decimal::of($h['AdditionalDiscount'] ?? 0);
        // the invoice discount lowers the cost of every line in proportion to its value
        $factor = $subtotal->isPositive() ? $subtotal->minus($additional)->dividedBy($subtotal, 10, RoundingMode::HalfUp) : BigDecimal::one();
        foreach ($lines as $line) {
            $variation = $this->variation($line['ItemId'], $key);
            $qty = Decimal::of($line['Quantity']);
            if (! $variation || ! $qty->isPositive()) {
                continue;
            }
            [$before, $unit, $total] = $this->prices($line, $qty);
            $landed = $unit->multipliedBy($factor)->toScale(4, RoundingMode::HalfUp);
            $item = PurchaseItem::query()->create([
                'purchase_id' => $purchase->id, 'variation_id' => $variation->id, 'unit_id' => $variation->product->unit_id, 'unit_multiplier' => 1,
                'qty' => Decimal::toDb($qty), 'base_qty' => Decimal::toDb($qty), 'unit_cost_before_discount' => Decimal::toDb($before),
                'discount_type' => $before->isGreaterThan($unit) ? 'fixed' : null, 'discount_value' => Decimal::toDb(BigDecimal::max($before->minus($unit), BigDecimal::zero())),
                'unit_cost' => Decimal::toDb($unit), 'tax_amount' => 0, 'unit_cost_inc_tax' => Decimal::toDb($unit), 'landed_unit_cost' => Decimal::toDb($landed),
                'line_total' => Decimal::toDb($total),
            ]);
            if ($variation->product->tracksStock()) {
                $this->stock->receive($variation, $this->location($line['StoreId'] ?? null, $h['BranchId']), $qty, $landed, StockMovementType::Purchase, $item, at: $date);
            }
            $this->serial($line, $variation, $location, bought: $item);
            $this->context->remember('InvoiceDetails', ImportContext::key($key, $line['Id']), $item);
        }
        $purchase->update(['subtotal' => Decimal::toDb($subtotal), 'discount_type' => $additional->isPositive() ? 'fixed' : null,
            'discount_value' => Decimal::toDb($additional), 'discount_amount' => Decimal::toDb($additional),
            'total' => Decimal::toDb($h['NetTotal'] ?? $subtotal->minus($additional)), 'due_total' => Decimal::toDb($h['NetTotal'] ?? 0), 'payment_status' => PaymentStatus::Due]);
        $this->pay($purchase, $key, PaymentDirection::Out, $purchase->supplier_id, $location, $date, null);
        $this->finish($purchase, 'Invoice', $key, 'purchases');
    }

    private function purchaseReturn(array $h, string $key): void
    {
        $lines = $this->lines[$key] ?? [];
        $location = $this->documentLocation($h);
        $date = Carbon::parse($h['InvoiceDate']);
        $purchaseId = null;
        foreach ($lines as $line) {
            [$origKey] = $this->returnLinks[ImportContext::key($key, $line['Id'])] ?? [null];
            $purchaseId ??= $origKey ? $this->context->newId('Invoice', $origKey) : null;
        }
        $return = PurchaseReturn::query()->create([
            'number' => $this->number('PR', $h), 'purchase_id' => $purchaseId,
            'supplier_id' => ($purchaseId ? Purchase::query()->whereKey($purchaseId)->value('supplier_id') : null) ?? $this->contact($h['SCEId'], ContactType::Supplier),
            'location_id' => $location, 'return_date' => $date, 'created_by' => $this->context->newId('Users', (string) ($h['UserIdAdded'] ?? '')),
        ]);
        $subtotal = BigDecimal::zero();
        foreach ($lines as $line) {
            $variation = $this->variation($line['ItemId'], $key);
            $qty = Decimal::of($line['Quantity']);
            if (! $variation || ! $qty->isPositive()) {
                continue;
            }
            [, $unit, $total] = $this->prices($line, $qty);
            [$origKey, $origLine] = $this->returnLinks[ImportContext::key($key, $line['Id'])] ?? [null, null];
            $purchaseItem = $origKey ? PurchaseItem::query()->find($this->context->newId('InvoiceDetails', ImportContext::key($origKey, $origLine))) : null;
            $item = $return->items()->create([
                'purchase_item_id' => $purchaseItem?->id, 'variation_id' => $variation->id, 'unit_id' => $variation->product->unit_id, 'unit_multiplier' => 1,
                'qty' => Decimal::toDb($qty), 'base_qty' => Decimal::toDb($qty), 'unit_cost' => Decimal::toDb($unit), 'tax_amount' => 0, 'line_total' => Decimal::toDb($total),
            ]);
            if ($variation->product->tracksStock()) {
                $this->stock->issue($variation, $location, $qty, StockMovementType::PurchaseReturn, $item, at: $date, allowNegative: true, fromSource: $purchaseItem);
            }
            $purchaseItem?->update(['qty_returned' => Decimal::toDb(Decimal::of($purchaseItem->qty_returned)->plus($qty))]);
            $subtotal = $subtotal->plus($total);
        }
        $net = Decimal::of($h['NetTotal'] ?? $subtotal);
        $return->update(['subtotal' => Decimal::toDb($subtotal), 'discount_amount' => Decimal::toDb($subtotal->minus($net)), 'tax_amount' => 0,
            'total' => Decimal::toDb($net), 'due_total' => Decimal::toDb($net), 'payment_status' => PaymentStatus::Due]);
        $this->pay($return, $key, PaymentDirection::In, $return->supplier_id, $location, $date, null);
        $this->finish($return, 'Invoice', $key, 'purchase returns');
    }

    /** 8 — stores opening balance. */
    private function opening(array $h, string $key): void
    {
        $this->adjustment($h, $key, StockAdjustmentType::Opening, $this->lines[$key] ?? [], 'opening stock');
    }

    /** 18 — execution (write-off) of goods. */
    private function writeOff(array $h, string $key): void
    {
        $this->adjustment($h, $key, StockAdjustmentType::Damage, $this->lines[$key] ?? [], 'write-offs');
    }

    /** 19 — stock count: the difference counted − system becomes an increase and / or a decrease. */
    private function count(array $h, string $key): void
    {
        $up = [];
        $down = [];
        foreach ($this->package->all('StocktakingDetails') as $d) {
            if (ImportContext::key($d['BranchId'], 19, $d['FYId'], $d['STId']) !== $key) {
                continue;
            }
            $diff = Decimal::of($d['ActualQuantity'] ?? 0)->minus(Decimal::of($d['SystemQuantity'] ?? 0));
            if ($diff->isZero()) {
                continue;
            }
            $line = ['Id' => $d['Id'], 'ItemId' => $d['ItemId'], 'Quantity' => (string) $diff->abs(), 'Cost' => $d['Cost'], 'StoreId' => $h['StoreId'] ?? null, 'Serial' => null];
            $diff->isPositive() ? $up[] = $line : $down[] = $line;
        }
        $this->adjustment($h, $key, StockAdjustmentType::Increase, $up, 'stock counts', __('legacy.stock_count'));
        $this->adjustment($h, $key.'|out', StockAdjustmentType::Decrease, $down, 'stock counts', __('legacy.stock_count'));
    }

    /** @param list<array<string, mixed>> $lines */
    private function adjustment(array $h, string $key, StockAdjustmentType $type, array $lines, string $counter, ?string $reason = null): void
    {
        if ($lines === [] || $this->context->newId('Invoice', $key)) {
            return;
        }
        $location = $this->documentLocation($h);
        $date = Carbon::parse($h['InvoiceDate']);
        $incoming = in_array($type, [StockAdjustmentType::Opening, StockAdjustmentType::Increase], true);
        $adjustment = StockAdjustment::query()->create([
            // one prefix per kind: an opening document and a stock count can share an old code in the same branch and year
            'number' => $this->number(match ($type) {
                StockAdjustmentType::Opening => 'OB',
                StockAdjustmentType::Increase => 'CNT+',
                StockAdjustmentType::Decrease => 'CNT-',
                default => 'DMG',
            }, $h),
            'location_id' => $location, 'adjusted_at' => $date, 'type' => $type,
            'reason' => $reason ?? (trim((string) ($h['Description'] ?? '')) ?: null),
            'created_by' => $this->context->newId('Users', (string) ($h['UserIdAdded'] ?? '')),
        ]);
        $total = BigDecimal::zero();
        foreach ($lines as $line) {
            $variation = $this->variation($line['ItemId'], $key);
            $qty = Decimal::of($line['Quantity']);
            if (! $variation || ! $qty->isPositive() || ! $variation->product->tracksStock()) {
                continue;
            }
            // document lines (opening stock) carry the line value in Cost / Total and the unit cost in Price;
            // stock-count lines carry the unit cost in Cost
            $cost = isset($line['Total']) && Decimal::of($line['Total'])->isPositive()
                ? Decimal::divide($line['Total'], $qty) : Decimal::of($line['Cost'] ?? 0);
            $item = $adjustment->items()->create(['variation_id' => $variation->id, 'unit_id' => $variation->product->unit_id, 'unit_multiplier' => 1,
                'qty' => Decimal::toDb($qty), 'base_qty' => Decimal::toDb($qty), 'unit_cost' => Decimal::toDb($cost), 'line_total' => Decimal::toDb($cost->multipliedBy($qty))]);
            if ($incoming) {
                $this->stock->receive($variation, $location, $qty, $cost, $type === StockAdjustmentType::Opening ? StockMovementType::Opening : StockMovementType::Count, $item, at: $date);
                $this->serial($line, $variation, $location);
                $total = $total->plus($cost->multipliedBy($qty));
            } else {
                $issue = $this->stock->issue($variation, $location, $qty, StockMovementType::Adjustment, $item, at: $date, allowNegative: true);
                $item->update(['unit_cost' => Decimal::toDb($issue->unitCost), 'line_total' => Decimal::toDb($issue->totalCost)]);
                $this->serial($line, $variation, $location, damaged: $type === StockAdjustmentType::Damage);
                $total = $total->plus($issue->totalCost);
            }
        }
        $adjustment->update(['total_cost' => Decimal::toDb($total)]);
        $this->finish($adjustment, 'Invoice', $key, $counter);
    }

    private function transfer(array $h, string $key): void
    {
        $from = $this->context->newId('Store', (string) $h['StoreIdFrom']);
        $to = $this->context->newId('Store', (string) $h['StoreIdTo']);
        if (! $from || ! $to || $from === $to) {
            $this->context->warn(__('legacy.warnings.document_skipped', ['doc' => 'GoodTransfer '.$key, 'reason' => 'store']));

            return;
        }
        $date = Carbon::parse($h['GTDate']);
        $transfer = StockTransfer::query()->create([
            'number' => 'TR'.$h['BranchId'].'-'.$date->format('y').'-'.($h['GTCode'] ?: $h['GTId']),
            'from_location_id' => $from, 'to_location_id' => $to, 'status' => StockTransferStatus::Received,
            'transfer_date' => $date, 'sent_at' => $date, 'received_at' => $date, 'note' => trim((string) ($h['Description'] ?? '')) ?: null,
        ]);
        $total = BigDecimal::zero();
        foreach ($this->package->all('GoodTransferDetails') as $d) {
            if (ImportContext::key($d['BranchId'], $d['FYId'], $d['GTId']) !== $key) {
                continue;
            }
            $variation = $this->variation($d['ItemId'], 'GoodTransfer '.$key);
            $qty = Decimal::of($d['Quantity']);
            if (! $variation || ! $qty->isPositive() || ! $variation->product->tracksStock()) {
                continue;
            }
            $item = $transfer->items()->create(['variation_id' => $variation->id, 'unit_id' => $variation->product->unit_id, 'unit_multiplier' => 1,
                'qty_sent' => Decimal::toDb($qty), 'qty_received' => Decimal::toDb($qty), 'base_qty' => Decimal::toDb($qty), 'unit_cost' => 0]);
            $issue = $this->stock->issue($variation, $from, $qty, StockMovementType::TransferOut, $item, at: $date, allowNegative: true);
            $this->stock->receive($variation, $to, $qty, $issue->unitCost, StockMovementType::TransferIn, $item, at: $date);
            $item->update(['unit_cost' => Decimal::toDb($issue->unitCost)]);
            $total = $total->plus($issue->totalCost);
        }
        $transfer->update(['total_cost' => Decimal::toDb($total)]);
        $this->finish($transfer, 'GoodTransfer', $key, 'transfers');
    }

    /** 38 — kept as quotations (no stock, no money). */
    private function quotation(array $h, string $key): void
    {
        $sale = Sale::query()->create([
            'public_token' => (string) Str::ulid(),
            'draft_no' => $this->number('QT', $h), 'status' => SaleStatus::Quotation, 'channel' => SaleChannel::Import,
            'location_id' => $this->location($h['StoreId'] ?? null, $h['BranchId']), 'customer_id' => $this->contact($h['SCEId'], ContactType::Customer),
            'sale_date' => Carbon::parse($h['InvoiceDate']), 'note' => trim((string) ($h['Description'] ?? '')) ?: null,
        ]);
        $subtotal = BigDecimal::zero();
        foreach ($this->lines[$key] ?? [] as $line) {
            $variation = $this->variation($line['ItemId'], $key);
            $qty = Decimal::of($line['Quantity']);
            if (! $variation) {
                continue;
            }
            $qty = $qty->isPositive() ? $qty : BigDecimal::one();
            [$before, $unit, $total] = $this->prices($line, $qty);
            $sale->items()->create(['product_id' => $variation->product_id, 'variation_id' => $variation->id, 'unit_id' => $variation->product->unit_id,
                'unit_multiplier' => 1, 'qty' => Decimal::toDb($qty), 'base_qty' => Decimal::toDb($qty), 'unit_price_before_discount' => Decimal::toDb($before),
                'unit_price' => Decimal::toDb($unit), 'unit_price_inc_tax' => Decimal::toDb($unit), 'tax_amount' => 0, 'line_total' => Decimal::toDb($total)]);
            $subtotal = $subtotal->plus($total);
        }
        $this->totals($sale, $h, $subtotal, ['due_total' => 0]);
        $this->finish($sale, 'Invoice', $key, 'quotations');
    }

    // ------------------------------------------------------------------ helpers

    /** @return array{0: BigDecimal, 1: BigDecimal, 2: BigDecimal} unit price before discount, unit price, line total */
    private function prices(array $line, BigDecimal $qty): array
    {
        $total = Decimal::of($line['TotalAfterDiscount3'] ?? $line['Total'] ?? 0);
        $gross = Decimal::of($line['Total'] ?? 0);
        $before = $gross->isPositive() ? Decimal::divide($gross, $qty) : Decimal::of($line['Price'] ?? 0);

        return [$before, Decimal::divide($total, $qty), $total];
    }

    /** Header figures taken as they were: subtotal of the lines, invoice discount, net total. */
    private function totals(Sale $sale, array $h, BigDecimal $subtotal, array $extra = []): void
    {
        $additional = Decimal::of($h['AdditionalDiscount'] ?? 0);
        $net = Decimal::of($h['NetTotal'] ?? $subtotal->minus($additional));
        $sale->update([
            'subtotal' => Decimal::toDb($subtotal), 'discount_type' => $additional->isPositive() ? 'fixed' : null,
            'discount_value' => Decimal::toDb($additional), 'discount_amount' => Decimal::toDb($additional), 'tax_amount' => 0,
            'round_off' => Decimal::toDb($net->minus($subtotal->minus($additional))), 'total' => Decimal::toDb($net),
            'due_total' => Decimal::toDb($net), 'payment_status' => PaymentStatus::Due, ...$extra,
        ]);
    }

    /** Cash paid on the invoice (type 5) → a payment allocated to it. Credit stays due. */
    private function pay(Model $document, string $key, PaymentDirection $direction, ?int $contactId, int $location, Carbon $date, ?int $sessionId): void
    {
        foreach ($this->payments[$key] ?? [] as $i => $p) {
            $amount = Decimal::of($p['amount']);
            $due = Decimal::of($document->refresh()->getAttribute('due_total'));
            $allocate = BigDecimal::min($amount, BigDecimal::max($due, BigDecimal::zero()));
            $payment = app(RecordPayment::class)->handle(new PaymentData(
                direction: $direction, paymentMethodId: (int) $this->cashMethod, amount: Decimal::toDb($amount), contactId: $contactId,
                locationId: $location, paidAt: $date, allocations: $allocate->isPositive() ? [[$document, Decimal::toDb($allocate)]] : [],
                // the cashier's custody box when the old payment went there, else the branch box
                accountId: $this->context->newId('Custody', $p['account']) ?? $this->moneyAccount($p['account']), cashSessionId: $sessionId,
            ));
            $this->context->remember('InvoicePayment', ImportContext::key($key, $i), $payment);
            $this->context->count($this->name(), 'invoice payments');
        }
        app(PaymentStatusRefresher::class)->refresh($document);
    }

    /** A real box / bank of the old system, if the payment named one. */
    private function moneyAccount(string $code): ?int
    {
        if ($code === '' || $this->context->newType('ChartOfAccount', $code) !== 'account') {
            return null;
        }
        $id = $this->context->newId('ChartOfAccount', $code);

        return in_array(DB::table('accounts')->where('id', $id)->value('sub_type'), ['cash', 'bank'], true) ? $id : null;
    }

    private function finish(Model $document, string $table, string $key, string $counter): void
    {
        $this->posting->sync($document);
        // credit documents change the contact's balance too, not only payments (refresh is deferred: done once per contact)
        $contactId = $document->getAttributes()['customer_id'] ?? $document->getAttributes()['supplier_id'] ?? null;
        if ($contactId) {
            app(ContactLedger::class)->refresh(Contact::query()->findOrFail($contactId));
        }
        $this->context->remember($table, $key, $document);
        $this->context->count($this->name(), $counter);
    }

    private function number(string $prefix, array $h): string
    {
        $year = substr((string) $h['InvoiceDate'], 2, 2);

        return $prefix.$h['BranchId'].'-'.$year.'-'.($h['InvoiceCode'] ?: $h['InvoiceId']);
    }

    /**
     * The branch a document belongs to: its own store, else its branch — as the old system counts it.
     * (A line may come from another store: its stock moves there, the document stays with its branch.)
     *
     * @param  array<string, mixed>  $h
     */
    private function documentLocation(array $h): int
    {
        return $this->location($h['StoreId'] ?? null, $h['BranchId']);
    }

    private function location(mixed $storeId, mixed $branchId): int
    {
        return $this->context->newId('Store', (string) $storeId)
            ?? $this->context->newId('Branch', (string) $branchId)
            ?? (int) Location::query()->orderBy('id')->value('id');
    }

    private function contact(mixed $sceId, ContactType $fallback): int
    {
        $id = $this->context->newId('SupCusEmp', (string) $sceId);
        if ($id) {
            return $id;
        }
        if ($fallback === ContactType::Customer) {
            return (int) Contact::query()->where('is_default', true)->value('id');
        }

        return $this->unknownSupplier ??= Contact::query()->firstOrCreate(['code' => 'S-UNKNOWN'],
            ['type' => ContactType::Supplier, 'name' => __('legacy.unknown_supplier')])->id;
    }

    private function variation(mixed $itemId, string $doc): ?Variation
    {
        $id = $this->context->newId('ItemVariation', (string) $itemId);
        if (! $id || ! isset($this->variations[$id])) {
            $this->context->warn(__('legacy.warnings.unknown_item', ['id' => $itemId, 'doc' => $doc]));

            return null;
        }

        return $this->variations[$id];
    }

    private function serialNote(array $line): ?string
    {
        $serial = trim((string) ($line['Serial'] ?? ''));

        return $serial !== '' ? 'S/N: '.$serial : (trim((string) ($line['Description'] ?? '')) ?: null);
    }

    /** IMEI / serial numbers: in stock when bought, sold with the sale line, back in stock when returned. */
    private function serial(array $line, Variation $variation, int $location, ?PurchaseItem $bought = null, ?SaleItem $sold = null,
        bool $returned = false, bool $damaged = false): void
    {
        $value = mb_substr(trim((string) ($line['Serial'] ?? '')), 0, 100);
        if ($value === '') {
            return;
        }
        $serial = Serial::query()->firstOrNew(['variation_id' => $variation->id, 'serial' => $value]);
        $serial->fill([
            'location_id' => $location,
            'status' => match (true) {
                $sold !== null => SerialStatus::Sold,
                $damaged => SerialStatus::Damaged,
                $returned => SerialStatus::InStock,
                default => $serial->status ?? SerialStatus::InStock,
            },
            'purchase_item_id' => $bought->id ?? $serial->purchase_item_id,
            'sale_item_id' => $sold->id ?? ($returned ? null : $serial->sale_item_id),
        ])->save();
        $this->context->count($this->name(), 'serials');
    }
}
