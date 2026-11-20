<?php

namespace App\Filament\Pos\Pages;

use App\Domain\CashManagement\Actions\CloseCashSession;
use App\Domain\CashManagement\Actions\OpenCashSession;
use App\Domain\CashManagement\Actions\RecordCashMovement;
use App\Domain\CashManagement\Enums\CashSessionStatus;
use App\Domain\CashManagement\Models\CashSession;
use App\Domain\CashManagement\Services\BranchDrawer;
use App\Domain\CashManagement\Services\CashSessionSummary;
use App\Domain\Catalog\Enums\ProductType;
use App\Domain\Catalog\Enums\TaxType;
use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Variation;
use App\Domain\Catalog\Services\PriceResolver;
use App\Domain\CRM\Actions\SaveContact;
use App\Domain\CRM\Enums\ContactType;
use App\Domain\CRM\Models\Contact;
use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Finance\Enums\PaymentMethodType;
use App\Domain\Finance\Models\PaymentMethod;
use App\Domain\Foundation\Models\Location;
use App\Domain\Inventory\Models\StockLevel;
use App\Domain\Payroll\Actions\AdvanceRequestWorkflow;
use App\Domain\Payroll\Models\AdvanceRequest;
use App\Domain\Sales\Actions\DeleteSaleDraft;
use App\Domain\Sales\Actions\SaveSale;
use App\Domain\Sales\Data\SaleData;
use App\Domain\Sales\Data\SaleLineData;
use App\Domain\Sales\Enums\SaleChannel;
use App\Domain\Sales\Enums\SaleStatus;
use App\Domain\Sales\Models\Sale;
use App\Domain\Shared\Enums\DiscountType;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Settings\InventorySettings;
use App\Settings\PosSettings;
use App\Settings\WeighingScaleSettings;
use App\Support\Decimal;
use App\Support\DomainException;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Locked;

/**
 * The cashier screen. The cart lives in the browser (Alpine) for speed; every action that matters
 * goes through the same domain actions as the back office, and permissions are enforced here
 * (a cashier without "edit price" cannot send a custom price, whatever the browser says).
 */
class PointOfSale extends Page
{
    protected string $view = 'filament.pos.point-of-sale';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?string $slug = 'sell';

    protected Width|string|null $maxContentWidth = Width::Full;

    #[Locked]
    public ?int $sessionId = null;

    #[Locked]
    public ?int $editingSaleId = null;

    public static function getNavigationLabel(): string
    {
        return __('nav.pos');
    }

    public function getTitle(): string|Htmlable
    {
        return '';
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('pos.sell');
    }

    public function mount(): void
    {
        $this->sessionId = OpenCashSession::currentFor($this->user())?->id;
    }

    // --- data for the screen ---------------------------------------------------------------------

    public function session(): ?CashSession
    {
        return $this->sessionId ? CashSession::query()->with('cashRegister.location')->find($this->sessionId) : null;
    }

    public function locationId(): ?int
    {
        return $this->session()?->cashRegister->location_id;
    }

    /** Everything the browser needs to search and price offline-fast. */
    public function bootstrap(): array
    {
        $pos = app(PosSettings::class);
        $user = $this->user();
        $walkIn = Contact::query()->where('is_default', true)->first();

        return [
            'catalog' => $this->locationId() ? $this->catalog($this->locationId()) : [],
            'categories' => Category::query()->whereNull('parent_id')->orderBy('sort')->get(['id', 'name'])->all(),
            'methods' => PaymentMethod::query()->where('is_active', true)->where('type', '!=', PaymentMethodType::Advance)
                ->orderBy('sort')->get(['id', 'name', 'type'])->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'type' => $m->type->value])->all(),
            'walkIn' => $walkIn ? $this->customerPayload($walkIn) : null,
            'can' => [
                'editPrice' => $user->can('pos.edit_price') && $pos->allow_price_edit,
                'editDiscount' => $user->can('pos.edit_discount') && $pos->allow_discount,
                'credit' => $user->can('pos.credit_sale') && $pos->enable_credit_sale,
                'draft' => $pos->enable_draft,
                'suspend' => $pos->enable_suspend,
                'quotation' => $pos->enable_quotation,
                'multiPay' => $pos->enable_multiple_payments,
                'card' => $pos->enable_card_payment,
                'cashMovements' => $user->can('cash_movements.create'),
                'close' => $user->can('cash_register.close'),
                'viewSales' => $user->can('pos.view_sales'),
                'overrideLimits' => $user->can('pos.override_limits'),
                // the cashier asks for an advance from the till (their user must be linked to an employee)
                'requestAdvance' => $user->can('advances.request') && $user->employeeProfile()->exists(),
                // without it the cashier counts the drawer without knowing what it should hold
                'seeExpected' => $user->can('cash_register.view_expected'),
            ],
            'scale' => $this->scaleSettings(),
            'shortcuts' => $pos->keyboard_shortcuts,
            'precision' => app(GeneralSettings::class)->currency_precision,
            'allowOverselling' => app(InventorySettings::class)->allow_overselling,
            'maxDiscount' => $user->can('pos.override_limits') ? null : ($user->max_discount_percent !== null ? (float) $user->max_discount_percent : null),
        ];
    }

    private function catalog(int $locationId): array
    {
        $stock = StockLevel::query()->where('location_id', $locationId)->pluck('qty_on_hand', 'variation_id');
        // items another branch has: an empty shelf here can point the customer there (names via otherBranches())
        $elsewhere = StockLevel::query()->where('location_id', '!=', $locationId)->where('qty_on_hand', '>', 0)
            ->distinct()->pluck('variation_id')->flip();
        // the cashier can peek at the cost of a cart line (eye icon) only with this permission
        $costs = $this->user()->can('products.view_purchase_price')
            ? StockLevel::query()->where('location_id', $locationId)->where('avg_cost', '>', 0)->pluck('avg_cost', 'variation_id')
            : null;
        $prices = app(PriceResolver::class);

        return Variation::query()
            ->with(['product.unit', 'product.units', 'product.taxRate', 'product.category', 'barcodes', 'prices'])
            ->whereHas('product', fn ($q) => $q->where('is_active', true)->where('is_sellable', true)
                ->whereNot('type', ProductType::Modifier)
                ->whereHas('locations', fn ($l) => $l->whereKey($locationId)))
            ->limit(20000)
            ->get()
            ->map(function (Variation $v) use ($stock, $prices, $costs, $elsewhere) {
                $p = $v->product;
                $units = collect([['id' => $p->unit_id, 'name' => $p->unit->short_name, 'multiplier' => 1]])
                    ->concat($p->units->map(fn ($u) => ['id' => $u->id, 'name' => $u->short_name, 'multiplier' => (float) $u->base_multiplier]));

                return [
                    'id' => $v->id,
                    'name' => $v->fullName(),
                    'sku' => $v->sku,
                    'barcodes' => $v->barcodes->pluck('barcode')->all(),
                    // the chips list top-level categories, so a sub-category item belongs to its parent's chip
                    'category_id' => $p->category->parent_id ?? $p->category_id,
                    'price' => $prices->sellPrice($v)->toFloat(),
                    'group_prices' => $v->prices->mapWithKeys(fn ($gp) => [$gp->price_group_id => $prices->sellPrice($v, $gp->price_group_id)->toFloat()])->all(),
                    'tax_rate' => (float) ($p->taxRate->rate ?? 0),
                    'tax_inclusive' => $p->tax_type === TaxType::Inclusive,
                    'units' => $units->values()->all(),
                    'stock' => $p->tracksStock() ? (float) ($stock[$v->id] ?? 0) : null,
                    'elsewhere' => $p->tracksStock() && isset($elsewhere[$v->id]),
                    'decimal' => (bool) $p->unit->allow_decimal,
                    // per base unit: the branch's average cost, or the purchase price before any stock arrived
                    'cost' => $costs === null ? null : (float) ($costs[$v->id] ?? $v->purchase_price ?? 0),
                ];
            })
            ->values()->all();
    }

    private function scaleSettings(): ?array
    {
        $scale = app(WeighingScaleSettings::class);

        return $scale->enabled ? [
            'prefix' => $scale->label_prefix, 'skuLength' => $scale->sku_length,
            'intLength' => $scale->qty_integer_length, 'decLength' => $scale->qty_decimal_length,
        ] : null;
    }

    private function customerPayload(Contact $c): array
    {
        return [
            'id' => $c->id, 'name' => $c->name, 'mobile' => $c->mobile, 'is_default' => (bool) $c->is_default,
            'balance' => (float) $c->balance, 'credit_limit' => $c->credit_limit !== null ? (float) $c->credit_limit : null,
            'price_group_id' => $c->customerGroup?->price_group_id,
        ];
    }

    // --- shift -----------------------------------------------------------------------------------

    /**
     * The branches the user may open a shift in — the branch is the cash drawer. A branch whose shift is still open
     * (another cashier) says by whom and since when.
     *
     * @return array<int, array{name: string, busy: ?string, since: ?string, session_id: ?int}>
     */
    public function shiftBranches(): array
    {
        $open = CashSession::query()->with(['user', 'cashRegister'])->where('status', CashSessionStatus::Open)->get()
            ->keyBy(fn (CashSession $s) => $s->cashRegister->location_id);

        return $this->user()->accessibleLocations()->where('is_active', true)->orderBy('name')->get()
            ->mapWithKeys(fn (Location $location) => [$location->id => [
                'name' => $location->name,
                'busy' => $open->has($location->id) ? ($open[$location->id]->user->name ?? '—') : null,
                'since' => $open->has($location->id) ? $open[$location->id]->opened_at->format('Y-m-d H:i') : null,
                'session_id' => $open->has($location->id) ? $open[$location->id]->id : null,
            ]])->all();
    }

    public function openShift(int $locationId, float $openingFloat = 0): array
    {
        abort_unless($this->user()->can('cash_register.open'), 403);
        abort_unless(array_key_exists($locationId, $this->shiftBranches()), 403);

        return $this->attempt(function () use ($locationId, $openingFloat) {
            $session = app(OpenCashSession::class)->handle(app(BranchDrawer::class)->for($locationId), $this->user(), max(0, $openingFloat));
            $this->sessionId = $session->id;

            return ['reload' => true];
        });
    }

    public function shiftSummary(): array
    {
        $session = $this->requireSession();
        $summary = app(CashSessionSummary::class)->summarize($session);

        // blind close: the amounts never reach the browser, only the number of invoices
        return $this->user()->can('cash_register.view_expected') ? $summary : ['blind' => true, 'sales_count' => $summary['sales_count']];
    }

    public function cashMovement(string $direction, float $amount, ?string $reason = null): array
    {
        abort_unless($this->user()->can('cash_movements.create'), 403);

        return $this->attempt(function () use ($direction, $amount, $reason) {
            app(RecordCashMovement::class)->handle($this->requireSession(), PaymentDirection::from($direction), $amount, $reason);

            return ['ok' => true];
        });
    }

    public function closeShift(float $counted, array $denominations = [], int $cardSlips = 0, ?string $note = null): array
    {
        abort_unless($this->user()->can('cash_register.close'), 403);

        return $this->attempt(function () use ($counted, $denominations, $cardSlips, $note) {
            $session = app(CloseCashSession::class)->handle($this->requireSession(), $counted, $denominations, $cardSlips, note: $note);
            $this->sessionId = null;

            return $this->user()->can('cash_register.view_expected')
                ? ['closed' => true, 'difference' => (float) $session->difference, 'expected' => (float) $session->expected_cash]
                : ['closed' => true];
        });
    }

    // --- customers -------------------------------------------------------------------------------

    public function searchCustomers(string $search): array
    {
        $search = trim($search);

        return Contact::query()->with('customerGroup')->whereIn('type', [ContactType::Customer, ContactType::Both])->where('is_active', true)
            ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('mobile', 'like', "%{$search}%")->orWhere('code', $search))
            ->limit(15)->get()->map(fn (Contact $c) => $this->customerPayload($c))->all();
    }

    public function quickAddCustomer(string $name, ?string $mobile = null): array
    {
        abort_unless($this->user()->can('customers.create'), 403);

        return $this->attempt(fn () => ['customer' => $this->customerPayload(
            app(SaveContact::class)->handle(['type' => ContactType::Customer->value, 'name' => trim($name), 'mobile' => $mobile])->load('customerGroup'))]);
    }

    // --- sales -----------------------------------------------------------------------------------

    /** Pay & finish. $cart = {customer_id, price_group_id, lines[], discount_type, discount_value, note}, $tenders = [{payment_method_id, amount}] */
    public function checkout(array $cart, array $tenders): array
    {
        return $this->attempt(function () use ($cart, $tenders) {
            $sale = $this->save($cart, SaleStatus::Final, $tenders);

            return [
                'sale' => ['id' => $sale->id, 'invoice_no' => $sale->invoice_no, 'total' => (float) $sale->total,
                    'due' => (float) $sale->due_total, 'change' => SaveSale::change($tenders, (string) $sale->total)->toFloat()],
                'receipt_url' => route('pos.receipt', $sale),
                'print' => app(PosSettings::class)->print_receipt_on_finalize,
            ];
        });
    }

    public function park(array $cart, string $status): array
    {
        $status = SaleStatus::from($status);
        abort_unless(in_array($status, [SaleStatus::Draft, SaleStatus::Suspended, SaleStatus::Quotation], true), 422);

        return $this->attempt(fn () => ['sale' => ['id' => ($sale = $this->save($cart, $status))->id, 'number' => $sale->draft_no]]);
    }

    /** Suspended / draft sales of this shift (or this user) to recall. */
    public function parkedSales(): array
    {
        return Sale::query()->with('customer')
            ->whereIn('status', [SaleStatus::Suspended, SaleStatus::Draft, SaleStatus::Quotation])
            ->where('location_id', $this->locationId())
            ->where(fn ($q) => $q->where('cash_session_id', $this->sessionId)->orWhere('created_by', $this->user()->id))
            ->latest()->limit(30)->get()
            ->map(fn (Sale $s) => ['id' => $s->id, 'number' => $s->draft_no, 'status' => $s->status->getLabel(),
                'customer' => $s->customer->name, 'total' => (float) $s->total, 'at' => $s->updated_at?->diffForHumans()])
            ->all();
    }

    public function recall(int $saleId): array
    {
        $sale = $this->parkedSale($saleId)->load(['customer.customerGroup', 'items' => fn ($q) => $q->whereNull('parent_item_id')]);
        $this->editingSaleId = $sale->id;

        return [
            'customer' => $this->customerPayload($sale->customer),
            'price_group_id' => $sale->price_group_id,
            'discount_type' => $sale->discount_type?->value,
            'discount_value' => (float) $sale->discount_value,
            'note' => $sale->note,
            'lines' => $sale->items->map(fn ($i) => [
                'variation_id' => $i->variation_id, 'unit_id' => $i->unit_id, 'qty' => (float) $i->qty,
                'price' => null, 'discount_type' => $i->discount_type?->value, 'discount_value' => (float) $i->discount_value, 'note' => $i->note,
            ])->all(),
            'number' => $sale->draft_no,
        ];
    }

    public function discardParked(int $saleId): array
    {
        return $this->attempt(function () use ($saleId) {
            app(DeleteSaleDraft::class)->handle($this->parkedSale($saleId));
            if ($this->editingSaleId === $saleId) {
                $this->editingSaleId = null;
            }

            return ['ok' => true];
        });
    }

    public function newSale(): void
    {
        $this->editingSaleId = null;
    }

    /**
     * The other branches that have this item, with their quantity — shown when it is out of stock here.
     *
     * @return list<array{name: string, qty: float}>
     */
    public function otherBranches(int $variationId): array
    {
        return StockLevel::query()->join('locations', 'locations.id', '=', 'stock_levels.location_id')
            ->where('stock_levels.variation_id', $variationId)->where('stock_levels.location_id', '!=', $this->locationId())
            ->where('stock_levels.qty_on_hand', '>', 0)->where('locations.is_active', true)
            ->orderByDesc('stock_levels.qty_on_hand')
            ->get(['locations.name', 'stock_levels.qty_on_hand'])
            ->map(fn ($row) => ['name' => (string) $row->getAttribute('name'), 'qty' => (float) $row->getAttribute('qty_on_hand')])
            ->all();
    }

    /** طلب سلفة from the till, for the employee linked to this user. */
    public function requestAdvance(float $amount, ?float $installment = null, ?string $reason = null): array
    {
        $user = $this->user();
        abort_unless($user->can('advances.request'), 403);

        return $this->attempt(function () use ($user, $amount, $installment, $reason) {
            $employee = $user->employeeProfile->contact ?? throw new DomainException(__('payroll.errors.not_linked'));
            app(AdvanceRequestWorkflow::class)->request($user, $employee, $amount, $installment ?: null, $reason);

            return ['ok' => true];
        });
    }

    /** @return list<array{amount: float, status: string, color: string, at: ?string, note: ?string}> the cashier's own latest requests */
    public function myAdvanceRequests(): array
    {
        return AdvanceRequest::query()->where('requested_by', $this->user()->id)->latest('id')->limit(5)->get()
            ->map(fn (AdvanceRequest $r) => ['amount' => (float) $r->payableAmount(), 'status' => $r->status->getLabel(),
                'color' => $r->status->getColor(), 'at' => $r->created_at?->diffForHumans(), 'note' => $r->decision_note])
            ->all();
    }

    public function recentSales(): array
    {
        abort_unless($this->user()->can('pos.view_sales'), 403);

        return Sale::query()->with('customer')->where('cash_session_id', $this->sessionId)->where('status', SaleStatus::Final)
            ->latest('id')->limit(15)->get()
            ->map(fn (Sale $s) => ['id' => $s->id, 'number' => $s->invoice_no, 'customer' => $s->customer->name,
                'total' => (float) $s->total, 'voided' => $s->voided_at !== null, 'receipt_url' => route('pos.receipt', $s)])
            ->all();
    }

    // ---------------------------------------------------------------------------------------------

    private function save(array $cart, SaleStatus $status, array $tenders = []): Sale
    {
        $session = $this->requireSession();
        $user = $this->user();
        $canPrice = $user->can('pos.edit_price') && app(PosSettings::class)->allow_price_edit;
        $canDiscount = $user->can('pos.edit_discount') && app(PosSettings::class)->allow_discount;

        $lines = array_map(function (array $line) use ($canPrice, $canDiscount) {
            // permissions are enforced server side: forbidden fields are simply ignored
            if (! $canPrice) {
                $line['price'] = null;
            }
            if (! $canDiscount) {
                $line['discount_type'] = null;
                $line['discount_value'] = 0;
            }

            return SaleLineData::fromArray($line);
        }, array_values($cart['lines'] ?? []));

        $data = new SaleData(
            locationId: $session->cashRegister->location_id,
            lines: $lines,
            status: $status,
            customerId: filled($cart['customer_id'] ?? null) ? (int) $cart['customer_id'] : null,
            channel: SaleChannel::Pos,
            cashSessionId: $session->id,
            priceGroupId: filled($cart['price_group_id'] ?? null) ? (int) $cart['price_group_id'] : null,
            discountType: $canDiscount ? DiscountType::coerce($cart['discount_type'] ?? null) : null,
            discountValue: $canDiscount ? (string) ($cart['discount_value'] ?? 0) : '0',
            tenders: array_map(fn ($t) => ['payment_method_id' => (int) $t['payment_method_id'], 'amount' => (string) Decimal::of($t['amount'] ?? 0),
                'reference' => $t['reference'] ?? null], array_values($tenders)),
            note: $cart['note'] ?? null,
        );

        $sale = app(SaveSale::class)->handle($data, $this->editingSaleId ? $this->parkedSale($this->editingSaleId) : null);
        $this->editingSaleId = null;

        return $sale;
    }

    private function parkedSale(int $id): Sale
    {
        return Sale::query()->where('location_id', $this->locationId())->where('status', '!=', SaleStatus::Final)->findOrFail($id);
    }

    private function requireSession(): CashSession
    {
        return $this->session() ?? throw new DomainException(__('cash.errors.no_session'));
    }

    private function user(): User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : abort(401);
    }

    /** Runs a domain call and turns business errors into {error: message} for the browser. */
    private function attempt(\Closure $callback): array
    {
        try {
            return $callback();
        } catch (DomainException $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
