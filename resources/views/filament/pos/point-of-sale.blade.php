@php
    $session = $this->session();
    $boot = $session ? $this->bootstrap() : null;
@endphp

<x-filament-panels::page>
    @include('filament.pos.styles')
    @php($help = \App\Support\Help\HelpTopics::forPage($this::class))

    @if (! $session)
        {{-- ============ no open shift: open one ============ --}}
        @php($branches = $this->shiftBranches())
        @php($firstFree = collect($branches)->filter(fn ($b) => $b['busy'] === null)->keys()->first() ?? array_key_first($branches))
        <div class="pos-open-shift" x-data="{ branch: @js($firstFree), branches: @js($branches), float: 0, error: null, busy: false }">
            <h2>@svg('heroicon-o-lock-open'){{ __('Open a shift') }}</h2>
            @if (count($branches) === 0)
                <p class="pos-muted">{{ __('You are not assigned to any branch.') }}</p>
            @else
                @if (count($branches) > 1)
                    <label>{{ __('Location') }}
                        <select x-model.number="branch" class="pos-input">
                            @foreach ($branches as $id => $b)
                                <option value="{{ $id }}">{{ $b['name'] }}{{ $b['busy'] ? ' — '.__('Open by :name', ['name' => $b['busy']]) : '' }}</option>
                            @endforeach
                        </select>
                    </label>
                @else
                    <p class="pos-open-branch">{{ collect($branches)->first()['name'] }}</p>
                @endif
                <template x-if="branches[branch]?.busy">
                    <div class="pos-error">
                        <span x-text="'{{ __('A shift is open here by') }} ' + branches[branch].busy + ' ({{ __('since') }} ' + branches[branch].since + '). {{ __('It must be closed first.') }}'"></span>
                        @can('cash_register.close_others')
                            <a class="pos-link" :href="'{{ url('admin/cash-sessions') }}/' + branches[branch].session_id">{{ __('Close it') }}</a>
                        @endcan
                    </div>
                </template>
                <label x-show="!branches[branch]?.busy">{{ __('Opening cash') }}
                    <input type="number" min="0" step="0.01" x-model.number="float" class="pos-input">
                </label>
                <p class="pos-error" x-show="error" x-text="error"></p>
                <button class="pos-btn pos-btn-primary pos-btn-lg" :disabled="busy || branches[branch]?.busy" x-show="!branches[branch]?.busy"
                        @click="busy = true; $wire.openShift(branch, float || 0).then(r => { busy = false; r.error ? error = r.error : window.location.reload() })">
                    {{ __('Open shift') }}
                </button>
            @endif
            <div class="pos-open-shift-help">@include('filament.pos.help-button')</div>
        </div>
    @else
        <script>
            window.posApp = function (boot) {
                const round = (v, p = boot.precision) => Math.round((Number(v) + Number.EPSILON) * 10 ** p) / 10 ** p;
                const byBarcode = new Map();
                boot.catalog.forEach(p => { p.barcodes.forEach(b => byBarcode.set(b, p)); byBarcode.set(p.sku.toLowerCase(), p); });
                const byId = new Map(boot.catalog.map(p => [p.id, p]));

                return {
                    boot, search: '', category: null, lines: [], customer: boot.walkIn, priceGroupId: boot.walkIn?.price_group_id ?? null,
                    discountType: null, discountValue: 0, note: '', editingNumber: null,
                    modal: null, tenders: [], toast: null, busy: false, bumped: null, success: null, clock: '',
                    customerQuery: '', customerResults: [], newCustomer: { name: '', mobile: '' },
                    parked: [], recent: [], summary: null, closing: { counted: 0, note: '' }, movement: { direction: 'in', amount: 0, reason: '' }, advance: { amount: null, installment: null, reason: '' }, myAdvances: [],

                    init() {
                        this.$nextTick(() => this.$refs.search?.focus());
                        const tick = () => this.clock = new Date().toLocaleTimeString(document.documentElement.lang || 'ar', { hour: '2-digit', minute: '2-digit' });
                        tick(); setInterval(tick, 30000);
                        window.addEventListener('keydown', e => this.shortcut(e));
                    },

                    // ---------- catalog ----------
                    get results() {
                        const q = this.search.trim().toLowerCase();
                        let list = boot.catalog;
                        if (this.category) list = list.filter(p => p.category_id === this.category);
                        if (q) list = list.filter(p => p.name.toLowerCase().includes(q) || p.sku.toLowerCase().includes(q) || p.barcodes.some(b => b.includes(q)));
                        return list.slice(0, 60);
                    },
                    price(p, unit) {
                        const base = this.priceGroupId && p.group_prices[this.priceGroupId] !== undefined ? p.group_prices[this.priceGroupId] : p.price;
                        return round(base * (unit?.multiplier ?? 1), 4);
                    },
                    scan() {
                        const code = this.search.trim();
                        if (!code) return;
                        const weighed = this.parseScale(code);
                        const product = weighed ? byBarcode.get(weighed.sku.toLowerCase()) : (byBarcode.get(code) ?? byBarcode.get(code.toLowerCase()));
                        if (product) { this.add(product, weighed?.qty ?? 1); this.search = ''; return; }
                        if (this.results.length === 1) { this.add(this.results[0]); this.search = ''; }
                    },
                    parseScale(code) {
                        const s = boot.scale;
                        if (!s || !code.startsWith(s.prefix) || code.length < s.prefix.length + s.skuLength + s.intLength + s.decLength) return null;
                        let i = s.prefix.length;
                        const sku = code.substr(i, s.skuLength); i += s.skuLength;
                        const qty = Number(code.substr(i, s.intLength)) + Number(code.substr(i + s.intLength, s.decLength)) / 10 ** s.decLength;
                        return qty > 0 ? { sku, qty } : null;
                    },

                    // ---------- cart ----------
                    // ---------- stock ----------
                    // base-unit qty a line takes out of stock
                    baseQty(line) { return Number(line.qty || 0) * (this.unit(line)?.multiplier ?? 1); },
                    inCart(p, except = null) { return this.lines.reduce((s, l) => s + (l.variation_id === p.id && l !== except ? this.baseQty(l) : 0), 0); },
                    // what is left on the shelf after this cart (null = not stock-tracked)
                    available(p) { return p.stock === null ? null : round(p.stock - this.inCart(p), 4); },
                    // largest qty this line may hold, in the line's own unit (null = unlimited)
                    maxQty(line) {
                        const p = this.product(line);
                        if (!p || p.stock === null || boot.allowOverselling) return null;
                        return Math.max(0, Math.floor(((p.stock - this.inCart(p, line)) / (this.unit(line)?.multiplier ?? 1)) * 10000) / 10000);
                    },
                    overStock(line) { const m = this.maxQty(line); return m !== null && Number(line.qty || 0) > m; },
                    outOfStock(p) { const a = this.available(p); return a !== null && a <= 0; },
                    noStockMessage(p) { return '{{ __('Not enough stock') }}: ' + p.name + ' — {{ __('Available') }}: ' + Math.max(0, p.stock - this.inCart(p)); },
                    // clamp a line to what's in stock; returns false if it had to be reduced
                    fit(line) {
                        const m = this.maxQty(line);
                        if (m === null || Number(line.qty || 0) <= m) return true;
                        line.qty = m;
                        this.flash(this.noStockMessage(this.product(line)), 'warn');
                        if (m === 0) this.remove(line);
                        return false;
                    },

                    // ---------- cart ----------
                    // out of stock here: name the branches that have it
                    async whereElse(p) {
                        if (!p.elsewhere) return;
                        const branches = await this.$wire.otherBranches(p.id);
                        if (!branches.length) return;
                        this.flash('{{ __('Not available here') }}: ' + p.name + ' — {{ __('Available at') }}: '
                            + branches.map(b => b.name + ' (' + round(b.qty, 2) + ')').join('، '), 'warn');
                    },
                    add(p, qty = 1) {
                        if (this.outOfStock(p)) {
                            if (!boot.allowOverselling) {
                                this.flash(this.noStockMessage(p), 'error');
                                return this.whereElse(p);
                            }
                            this.flash('{{ __('Out of stock') }}: ' + p.name, 'warn');
                        }
                        const existing = this.lines.find(l => l.variation_id === p.id && l.unit_id === p.units[0].id && !l.custom);
                        if (existing) { existing.qty = round(Number(existing.qty) + qty, 4); this.fit(existing); this.bump(existing.key); return; }
                        if (!boot.allowOverselling && p.stock !== null && qty > p.stock - this.inCart(p)) qty = Math.max(0, round(p.stock - this.inCart(p), 4));
                        if (qty <= 0) return this.flash(this.noStockMessage(p), 'error');
                        const key = crypto.randomUUID(); this.bump(key);
                        this.lines.push({ key: key, variation_id: p.id, name: p.name, unit_id: p.units[0].id,
                            qty, price: null, discount_type: null, discount_value: 0, note: '', custom: false });
                    },
                    product(line) { return byId.get(line.variation_id); },
                    unit(line) { return this.product(line)?.units.find(u => u.id === line.unit_id); },
                    // cost of one selected unit — only sent to users who may see purchase prices
                    cost(line) { const p = this.product(line); return p?.cost == null ? null : p.cost * (this.unit(line)?.multiplier ?? 1); },
                    unitPrice(line) { return line.price !== null && line.price !== '' ? Number(line.price) : this.price(this.product(line), this.unit(line)); },
                    // net unit price before tax, after the line discount — compared with the cost (the server enforces it)
                    netUnit(line) {
                        const p = this.product(line); if (!p) return 0;
                        let unit = this.unitPrice(line);
                        const d = Number(line.discount_value || 0);
                        unit = line.discount_type === 'percent' ? unit * (1 - Math.min(d, 100) / 100) : (line.discount_type === 'fixed' ? Math.max(0, unit - d) : unit);
                        return p.tax_inclusive ? unit / (1 + p.tax_rate / 100) : unit;
                    },
                    belowCost(line) {
                        const c = this.cost(line);
                        return !boot.can.overrideLimits && c !== null && c > 0 && (line.custom || line.discount_type) && round(this.netUnit(line)) < round(c);
                    },
                    lineTotal(line) {
                        const p = this.product(line); if (!p) return 0;
                        let unit = this.unitPrice(line);
                        const d = Number(line.discount_value || 0);
                        unit = line.discount_type === 'percent' ? unit * (1 - Math.min(d, 100) / 100) : (line.discount_type === 'fixed' ? Math.max(0, unit - d) : unit);
                        if (!p.tax_inclusive) unit = unit * (1 + p.tax_rate / 100);
                        return round(unit * Number(line.qty || 0));
                    },
                    get subtotal() { return round(this.lines.reduce((s, l) => s + this.lineTotal(l), 0)); },
                    get invoiceDiscount() {
                        const v = Number(this.discountValue || 0);
                        return round(Math.min(this.subtotal, this.discountType === 'percent' ? this.subtotal * Math.min(v, 100) / 100 : (this.discountType === 'fixed' ? v : 0)));
                    },
                    get total() { return round(this.subtotal - this.invoiceDiscount); },
                    // the invoice discount may not take the net (before tax) under the cost of the cart — enforced by the server
                    get invoiceBelowCost() {
                        if (boot.can.overrideLimits || !this.invoiceDiscount || !this.subtotal) return false;
                        if (this.lines.some(l => this.cost(l) === null)) return false;
                        const net = this.lines.reduce((s, l) => s + this.netUnit(l) * Number(l.qty || 0), 0);
                        const cost = this.lines.reduce((s, l) => s + this.cost(l) * Number(l.qty || 0), 0);
                        return cost > 0 && round(net * (1 - this.invoiceDiscount / this.subtotal)) < round(cost);
                    },
                    get count() { return this.lines.reduce((s, l) => s + Number(l.qty || 0), 0); },
                    step(line, delta) {
                        if (delta > 0) { const m = this.maxQty(line); if (m !== null && Number(line.qty) + delta > m) return this.flash(this.noStockMessage(this.product(line)), 'error'); }
                        line.qty = Math.max(0, round(Number(line.qty) + delta, 4)); if (line.qty === 0) this.remove(line);
                    },
                    remove(line) { this.lines = this.lines.filter(l => l.key !== line.key); },
                    clear() { this.lines = []; this.discountType = null; this.discountValue = 0; this.note = ''; this.editingNumber = null; this.setCustomer(boot.walkIn); this.$wire.newSale(); },
                    cart() {
                        return { customer_id: this.customer?.id, price_group_id: this.priceGroupId, discount_type: this.discountType,
                            discount_value: this.discountValue, note: this.note,
                            lines: this.lines.map(l => ({ variation_id: l.variation_id, unit_id: l.unit_id, qty: l.qty,
                                price: l.price === '' ? null : l.price, discount_type: l.discount_type, discount_value: l.discount_value, note: l.note })) };
                    },

                    // ---------- customer ----------
                    setCustomer(c) { this.customer = c; this.priceGroupId = c?.price_group_id ?? null; this.customerQuery = ''; this.customerResults = []; },
                    async findCustomers() { this.customerResults = this.customerQuery.length < 2 ? [] : await this.$wire.searchCustomers(this.customerQuery); },
                    async addCustomer() {
                        const r = await this.$wire.quickAddCustomer(this.newCustomer.name, this.newCustomer.mobile);
                        if (r.error) return this.flash(r.error, 'error');
                        this.setCustomer(r.customer); this.newCustomer = { name: '', mobile: '' }; this.modal = null;
                    },

                    // ---------- payment ----------
                    openPay(type = null) {
                        if (!this.lines.length) return;
                        const method = boot.methods.find(m => m.type === (type ?? 'cash')) ?? boot.methods[0];
                        this.tenders = [{ payment_method_id: method.id, amount: this.total }];
                        this.modal = 'pay';
                    },
                    get tendered() { return round(this.tenders.reduce((s, t) => s + Number(t.amount || 0), 0)); },
                    get change() { return Math.max(0, round(this.tendered - this.total)); },
                    get remaining() { return Math.max(0, round(this.total - this.tendered)); },
                    addTender() { this.tenders.push({ payment_method_id: boot.methods[0].id, amount: this.remaining }); },
                    async checkout(tenders) {
                        if (this.busy || !this.lines.length) return;
                        const short = this.lines.find(l => this.overStock(l));
                        if (short) return this.flash(this.noStockMessage(this.product(short)), 'error');
                        this.busy = true;
                        const r = await this.$wire.checkout(this.cart(), tenders ?? this.tenders);
                        this.busy = false;
                        if (r.error) return this.flash(r.error, 'error');
                        this.modal = null;
                        this.success = { number: r.sale.invoice_no, total: r.sale.total, change: r.sale.change };
                        clearTimeout(this._s); this._s = setTimeout(() => { this.success = null; this.$refs.search?.focus(); }, 2600);
                        if (r.print) this.print(r.receipt_url);
                        // keep the on-screen stock in step with what was just sold
                        this.lines.forEach(l => { const p = this.product(l); if (p && p.stock !== null) p.stock = round(p.stock - this.baseQty(l), 4); });
                        this.clear();
                    },
                    expressCash() { this.checkout([{ payment_method_id: boot.methods.find(m => m.type === 'cash')?.id, amount: this.total }]); },
                    credit() { this.checkout([]); },
                    print(url) { const f = this.$refs.printFrame; f.src = url + (url.includes('?') ? '&' : '?') + 'print=1&t=' + Date.now(); },

                    // ---------- parked ----------
                    async park(status) {
                        if (!this.lines.length) return;
                        const r = await this.$wire.park(this.cart(), status);
                        if (r.error) return this.flash(r.error, 'error');
                        this.flash('{{ __('Saved') }}: ' + r.sale.number, 'ok'); this.clear();
                    },
                    async showParked() { this.parked = await this.$wire.parkedSales(); this.modal = 'parked'; },
                    async recall(id) {
                        const r = await this.$wire.recall(id);
                        this.setCustomer(r.customer); this.priceGroupId = r.price_group_id ?? this.priceGroupId;
                        this.discountType = r.discount_type; this.discountValue = r.discount_value; this.note = r.note ?? ''; this.editingNumber = r.number;
                        this.lines = r.lines.filter(l => byId.has(l.variation_id)).map(l => ({ ...l, key: crypto.randomUUID(), name: byId.get(l.variation_id).name, custom: false }));
                        this.modal = null;
                    },
                    async discard(id) { const r = await this.$wire.discardParked(id); if (r.error) return this.flash(r.error, 'error'); this.parked = await this.$wire.parkedSales(); },
                    async showRecent() { this.recent = await this.$wire.recentSales(); this.modal = 'recent'; },

                    // ---------- shift ----------
                    // ---------- advance request ----------
                    async showAdvance() { this.myAdvances = await this.$wire.myAdvanceRequests(); this.modal = 'advance'; },
                    async sendAdvance() {
                        const r = await this.$wire.requestAdvance(Number(this.advance.amount), this.advance.installment ? Number(this.advance.installment) : null, this.advance.reason);
                        if (r.error) return this.flash(r.error, 'error');
                        this.flash('{{ __('Request sent for approval') }}', 'ok');
                        this.advance = { amount: null, installment: null, reason: '' }; this.modal = null;
                    },
                    async saveMovement() {
                        const r = await this.$wire.cashMovement(this.movement.direction, Number(this.movement.amount), this.movement.reason);
                        if (r.error) return this.flash(r.error, 'error');
                        this.flash('{{ __('Saved') }}', 'ok'); this.movement = { direction: 'in', amount: 0, reason: '' }; this.modal = null;
                    },
                    async showClose() {
                        this.summary = await this.$wire.shiftSummary();
                        // blind close: the cashier types what is really in the drawer
                        this.closing.counted = this.summary.blind ? null : this.summary.expected_cash;
                        this.modal = 'close';
                    },
                    async closeShift() {
                        if (this.closing.counted === null || this.closing.counted === '') return this.flash('{{ __('Enter the cash you counted in the drawer.') }}', 'error');
                        const r = await this.$wire.closeShift(Number(this.closing.counted), {}, 0, this.closing.note);
                        if (r.error) return this.flash(r.error, 'error');
                        alert(r.difference === undefined ? '{{ __('Shift closed.') }}' : '{{ __('Shift closed. Difference') }}: ' + r.difference.toFixed(boot.precision));
                        window.location.reload();
                    },

                    // ---------- looks ----------
                    bump(key) { this.bumped = key; clearTimeout(this._b); this._b = setTimeout(() => this.bumped = null, 450); },
                    catColor(id) { return id ? `hsl(${(id * 67) % 360} 62% 46%)` : 'hsl(220 9% 46%)'; },
                    initial(name) { return (name || '?').replace(/^(ال)/, '').trim().charAt(0).toUpperCase(); },
                    hint(action) { const k = (boot.shortcuts || {})[action]; return k ? k.toUpperCase() : ''; },
                    categoryOf(p) { return p.category_id ?? null; },

                    // ---------- misc ----------
                    money(v) { return Number(v || 0).toFixed(boot.precision); },
                    flash(text, kind = 'ok') { this.toast = { text, kind }; clearTimeout(this._t); this._t = setTimeout(() => this.toast = null, kind === 'error' ? 7000 : 3500); },
                    shortcut(e) {
                        const combo = [e.shiftKey && 'shift', e.ctrlKey && 'ctrl', e.altKey && 'alt', e.key.toLowerCase()].filter(Boolean).join('+');
                        const map = boot.shortcuts || {};
                        const action = Object.keys(map).find(k => (map[k] || '').toLowerCase() === combo);
                        if (!action) { if (e.key === 'Escape') this.modal = null; return; }
                        e.preventDefault();
                        ({ express_checkout: () => this.expressCash(), pay_card: () => this.openPay('card'), multiple_pay: () => this.openPay(),
                           draft: () => this.park('draft'), suspend: () => this.park('suspended'), cancel: () => this.clear(),
                           focus_search: () => this.$refs.search?.focus(), add_customer: () => this.modal = 'customer' })[action]?.();
                    },
                };
            };
        </script>

        <div class="pos" x-data="posApp(@js($boot))" x-cloak>
            {{-- ===================== products ===================== --}}
            <section class="pos-products">
                <div class="pos-topbar">
                    <div class="pos-searchbar">
                        @svg('heroicon-o-magnifying-glass', 'pos-search-ico')
                        <input x-ref="search" x-model="search" @keydown.enter.prevent="scan()" class="pos-input pos-search"
                               placeholder="{{ __('Search or scan barcode…') }}" autocomplete="off">
                        <kbd class="pos-kbd" x-show="hint('focus_search')" x-text="hint('focus_search')"></kbd>
                    </div>
                    <div class="pos-tools">
                        <button class="pos-tool" @click="showParked()" title="{{ __('Recall') }}">@svg('heroicon-o-inbox-stack')<span>{{ __('Recall') }}</span></button>
                        <button class="pos-tool" x-show="boot.can.viewSales" @click="showRecent()" title="{{ __('Recent') }}">@svg('heroicon-o-clock')<span>{{ __('Recent') }}</span></button>
                        <button class="pos-tool" x-show="boot.can.requestAdvance" @click="showAdvance()" title="{{ __('Request an advance') }}">@svg('heroicon-o-hand-raised')<span>{{ __('Advance') }}</span></button>
                        <button class="pos-tool" x-show="boot.can.cashMovements" @click="modal = 'movement'" title="{{ __('Cash in / out') }}">@svg('heroicon-o-arrows-right-left')<span>{{ __('Cash in / out') }}</span></button>
                        <button class="pos-tool is-danger" x-show="boot.can.close" @click="showClose()" title="{{ __('Close shift') }}">@svg('heroicon-o-lock-closed')<span>{{ __('Close shift') }}</span></button>
                        @include('filament.pos.help-button')
                        @if (auth()->user()?->canAccessPanel(\Filament\Facades\Filament::getPanel('admin')))
                            <a class="pos-tool" href="{{ \App\Filament\Admin\Pages\Dashboard::getUrl(panel: 'admin') }}" title="{{ __('Dashboard') }}">@svg('heroicon-o-squares-2x2')<span>{{ __('Dashboard') }}</span></a>
                        @endif
                        <span class="pos-branch" title="{{ __('Location') }}">@svg('heroicon-o-building-storefront'){{ $this->session()?->cashRegister->location->name }}</span>
                        <span class="pos-clock" x-text="clock"></span>
                    </div>
                </div>
                <div class="pos-chips">
                    <button class="pos-chip" :class="{ 'is-active': category === null }" @click="category = null">@svg('heroicon-m-squares-2x2'){{ __('All') }}</button>
                    <template x-for="c in boot.categories" :key="c.id">
                        <button class="pos-chip" :class="{ 'is-active': category === c.id }" :style="{ '--cat': catColor(c.id) }" @click="category = c.id">
                            <span class="pos-dot"></span><span x-text="c.name"></span>
                        </button>
                    </template>
                </div>
                <div class="pos-grid">
                    <template x-for="p in results" :key="p.id">
                        <button class="pos-card" :class="{ 'is-out': outOfStock(p) && !boot.allowOverselling }" @click="add(p)">
                            <span class="pos-card-top">
                                <span class="pos-stock" :class="{ 'is-low': outOfStock(p), 'is-few': !outOfStock(p) && available(p) !== null && available(p) <= 5 }"
                                      x-show="p.stock !== null" x-text="available(p)"></span>
                            </span>
                            <span class="pos-card-name" x-text="p.name"></span>
                            <span class="pos-elsewhere" x-show="p.elsewhere && outOfStock(p)">@svg('heroicon-m-building-storefront'){{ __('Available at another branch') }}</span>
                            <span class="pos-card-price" x-text="money(price(p))"></span>
                        </button>
                    </template>
                    <div class="pos-nothing" x-show="!results.length">@svg('heroicon-o-face-frown'){{ __('No products found.') }}</div>
                </div>
            </section>

            {{-- ===================== cart ===================== --}}
            <section class="pos-cart">
                <div class="pos-customer">
                    <div class="pos-customer-current">
                        <span class="pos-avatar" x-text="initial(customer?.name)"></span>
                        <div class="pos-customer-info">
                            <strong x-text="customer?.name"></strong>
                            <span class="pos-muted" x-show="customer && !customer.is_default && customer.balance"
                                  :class="{ 'pos-owes': customer?.balance > 0 }" x-text="'{{ __('Balance') }}: ' + money(customer?.balance)"></span>
                        </div>
                        <span class="pos-badge" x-show="priceGroupId">@svg('heroicon-m-tag'){{ __('Price group') }}</span>
                    </div>
                    <div class="pos-customer-search">
                        <input x-model="customerQuery" @input.debounce.300ms="findCustomers()" class="pos-input" placeholder="{{ __('Customer name or mobile…') }}">
                        <button class="pos-btn pos-btn-icon" @click="modal = 'customer'" title="{{ __('New customer') }}">@svg('heroicon-o-user-plus')</button>
                        <button class="pos-btn pos-btn-icon" x-show="customer && !customer.is_default" @click="setCustomer(boot.walkIn)" title="{{ __('Walk-in customer') }}">@svg('heroicon-o-x-mark')</button>
                        <div class="pos-dropdown" x-show="customerResults.length" @click.outside="customerResults = []">
                            <template x-for="c in customerResults" :key="c.id">
                                <button @click="setCustomer(c)"><span class="pos-avatar is-small" x-text="initial(c.name)"></span>
                                    <span x-text="c.name"></span> <span class="pos-muted" x-text="c.mobile"></span></button>
                            </template>
                        </div>
                    </div>
                </div>

                <div class="pos-editing" x-show="editingNumber">@svg('heroicon-m-pencil-square'){{ __('Editing') }} <strong x-text="editingNumber"></strong></div>

                <div class="pos-lines">
                    <template x-for="line in lines" :key="line.key">
                        <div class="pos-line" :class="{ 'is-over': overStock(line) || belowCost(line), 'is-bumped': bumped === line.key }">
                            <div class="pos-line-head">
                                <span class="pos-line-name" x-text="line.name"></span>
                                <template x-if="cost(line) !== null">
                                    <span class="pos-cost">
                                        <span class="pos-cost-value" x-show="line.showCost" x-text="'{{ __('Cost') }}: ' + money(cost(line))"></span>
                                        <button type="button" class="pos-cost-btn" @click="line.showCost = !line.showCost"
                                                :title="line.showCost ? '{{ __('Hide cost') }}' : '{{ __('Show cost') }}'">
                                            <span x-show="!line.showCost">@svg('heroicon-o-eye')</span>
                                            <span x-show="line.showCost">@svg('heroicon-o-eye-slash')</span>
                                        </button>
                                    </span>
                                </template>
                                <button class="pos-x" @click="remove(line)" title="{{ __('Remove') }}">@svg('heroicon-m-trash')</button>
                            </div>
                            <div class="pos-line-body">
                                <div class="pos-qty">
                                    <button @click="step(line, -1)">−</button>
                                    <input type="number" step="any" min="0" :max="maxQty(line)" x-model.number="line.qty" @change="fit(line)">
                                    <button @click="step(line, 1)">+</button>
                                </div>
                                <select x-model.number="line.unit_id" @change="fit(line)" class="pos-input pos-unit" x-show="product(line)?.units.length > 1">
                                    <template x-for="u in product(line)?.units" :key="u.id"><option :value="u.id" x-text="u.name"></option></template>
                                </select>
                                <input type="number" step="any" min="0" class="pos-input pos-price" :placeholder="money(price(product(line), unit(line)))"
                                       x-model="line.price" :disabled="!boot.can.editPrice" @input="line.custom = true">
                                <template x-if="boot.can.editDiscount">
                                    <span class="pos-line-discount">
                                        <select x-model="line.discount_type" class="pos-input"><option value="">{{ __('No discount') }}</option><option value="percent">%</option><option value="fixed">{{ __('Amount') }}</option></select>
                                        <input type="number" min="0" step="any" class="pos-input" x-show="line.discount_type" x-model.number="line.discount_value">
                                    </span>
                                </template>
                                <strong class="pos-line-total" x-text="money(lineTotal(line))"></strong>
                                <span class="pos-below-cost" x-show="belowCost(line)">{{ __('Below cost') }}</span>
                            </div>
                        </div>
                    </template>
                    <div class="pos-empty" x-show="!lines.length">
                        @svg('heroicon-o-shopping-cart')
                        <p>{{ __('Scan a barcode or pick a product to start.') }}</p>
                    </div>
                </div>

                <div class="pos-summary">
                    <div class="pos-summary-row"><span>{{ __('Items') }}</span><span x-text="count"></span></div>
                    <div class="pos-summary-row"><span>{{ __('Subtotal') }}</span><span x-text="money(subtotal)"></span></div>
                    <div class="pos-summary-row pos-invoice-discount" x-show="boot.can.editDiscount">
                        <span>{{ __('Discount') }}</span>
                        <span>
                            <select x-model="discountType" class="pos-input"><option value="">—</option><option value="percent">%</option><option value="fixed">{{ __('Amount') }}</option></select>
                            <input type="number" min="0" step="any" class="pos-input" x-show="discountType" x-model.number="discountValue">
                            <span x-show="invoiceDiscount" x-text="'-' + money(invoiceDiscount)"></span>
                        </span>
                        <div class="pos-below-cost" x-show="invoiceBelowCost">{{ __('The discount takes the invoice below cost') }}</div>
                    </div>
                    <div class="pos-grand"><span>{{ __('Total') }}</span><span x-text="money(total)"></span></div>
                </div>

                <div class="pos-pay">
                    <button class="pos-pay-btn is-cash" :disabled="!lines.length || busy" @click="expressCash()">
                        @svg('heroicon-o-banknotes')<span>{{ __('Pay cash') }}</span><kbd x-show="hint('express_checkout')" x-text="hint('express_checkout')"></kbd>
                    </button>
                    <button class="pos-pay-btn is-card" x-show="boot.can.card" :disabled="!lines.length || busy" @click="openPay('card')">
                        @svg('heroicon-o-credit-card')<span>{{ __('Card') }}</span><kbd x-show="hint('pay_card')" x-text="hint('pay_card')"></kbd>
                    </button>
                    <button class="pos-pay-btn is-multi" x-show="boot.can.multiPay" :disabled="!lines.length || busy" @click="openPay()">
                        @svg('heroicon-o-square-3-stack-3d')<span>{{ __('Multiple payments') }}</span><kbd x-show="hint('multiple_pay')" x-text="hint('multiple_pay')"></kbd>
                    </button>
                    <button class="pos-pay-btn is-credit" x-show="boot.can.credit && customer && !customer.is_default" :disabled="!lines.length || busy" @click="credit()">
                        @svg('heroicon-o-document-text')<span>{{ __('Credit sale') }}</span>
                    </button>
                </div>
                <div class="pos-secondary">
                    <button class="pos-btn" x-show="boot.can.suspend" :disabled="!lines.length" @click="park('suspended')">@svg('heroicon-m-pause'){{ __('Suspend') }}</button>
                    <button class="pos-btn" x-show="boot.can.draft" :disabled="!lines.length" @click="park('draft')">@svg('heroicon-m-pencil'){{ __('Draft') }}</button>
                    <button class="pos-btn" x-show="boot.can.quotation" :disabled="!lines.length" @click="park('quotation')">@svg('heroicon-m-document-text'){{ __('Quotation') }}</button>
                    <button class="pos-btn pos-btn-danger-soft" :disabled="!lines.length" @click="clear()">@svg('heroicon-m-x-mark'){{ __('Cancel') }}</button>
                </div>
            </section>

            {{-- ===================== modals ===================== --}}
            <div class="pos-backdrop" x-show="modal" x-transition.opacity @click.self="modal = null">
                <div class="pos-modal" x-show="modal === 'pay'">
                    <div class="pos-modal-total"><span>{{ __('Payment') }}</span><strong x-text="money(total)"></strong></div>
                    <template x-for="(t, i) in tenders" :key="i">
                        <div class="pos-row">
                            <select x-model.number="t.payment_method_id" class="pos-input">
                                <template x-for="m in boot.methods" :key="m.id"><option :value="m.id" x-text="m.name"></option></template>
                            </select>
                            <input type="number" min="0" step="any" x-model.number="t.amount" class="pos-input">
                            <button class="pos-x" x-show="tenders.length > 1" @click="tenders.splice(i, 1)">✕</button>
                        </div>
                    </template>
                    <div class="pos-row" x-show="boot.can.multiPay"><button class="pos-btn" @click="addTender()">{{ __('Add payment method') }}</button></div>
                    <div class="pos-quick">
                        <template x-for="v in [total, Math.ceil(total / 50) * 50, Math.ceil(total / 100) * 100, Math.ceil(total / 200) * 200].filter((v, i, a) => a.indexOf(v) === i)">
                            <button class="pos-btn" @click="tenders[tenders.length - 1].amount = v" x-text="money(v)"></button>
                        </template>
                    </div>
                    <div class="pos-totals">
                        <div><span>{{ __('Paid') }}</span><span x-text="money(tendered)"></span></div>
                        <div x-show="remaining > 0" class="pos-warn"><span>{{ __('Remaining') }}</span><span x-text="money(remaining)"></span></div>
                        <div x-show="change > 0" class="pos-change"><span>{{ __('Change') }}</span><span x-text="money(change)"></span></div>
                    </div>
                    <div class="pos-actions"><button class="pos-pay-btn is-cash is-wide" :disabled="busy" @click="checkout()">@svg('heroicon-o-check-circle')<span>{{ __('Finish sale') }}</span></button></div>
                </div>

                <div class="pos-modal" x-show="modal === 'customer'">
                    <h3>{{ __('New customer') }}</h3>
                    <input class="pos-input" x-model="newCustomer.name" placeholder="{{ __('Name') }}">
                    <input class="pos-input" x-model="newCustomer.mobile" placeholder="{{ __('Mobile') }}">
                    <div class="pos-actions"><button class="pos-btn pos-btn-primary" :disabled="!newCustomer.name" @click="addCustomer()">{{ __('Save') }}</button></div>
                </div>

                <div class="pos-modal pos-modal-wide" x-show="modal === 'parked'">
                    <h3>{{ __('Suspended & draft sales') }}</h3>
                    <table class="pos-table">
                        <template x-for="s in parked" :key="s.id">
                            <tr><td x-text="s.number"></td><td x-text="s.status"></td><td x-text="s.customer"></td><td x-text="money(s.total)"></td><td class="pos-muted" x-text="s.at"></td>
                                <td><button class="pos-btn pos-btn-primary" @click="recall(s.id)">{{ __('Open') }}</button> <button class="pos-btn pos-btn-danger" @click="discard(s.id)">✕</button></td></tr>
                        </template>
                    </table>
                    <p class="pos-muted" x-show="!parked.length">{{ __('Nothing here.') }}</p>
                </div>

                <div class="pos-modal pos-modal-wide" x-show="modal === 'recent'">
                    <h3>{{ __('Recent sales of this shift') }}</h3>
                    <table class="pos-table">
                        <template x-for="s in recent" :key="s.id">
                            <tr :class="{ 'is-void': s.voided }"><td x-text="s.number"></td><td x-text="s.customer"></td><td x-text="money(s.total)"></td>
                                <td><button class="pos-btn" @click="print(s.receipt_url)">{{ __('Print') }}</button></td></tr>
                        </template>
                    </table>
                    <p class="pos-muted" x-show="!recent.length">{{ __('Nothing here.') }}</p>
                </div>

                <div class="pos-modal" x-show="modal === 'movement'">
                    <h3>{{ __('Cash in / out') }}</h3>
                    <select class="pos-input" x-model="movement.direction"><option value="in">{{ __('Cash in') }}</option><option value="out">{{ __('Cash out') }}</option></select>
                    <input class="pos-input" type="number" min="0" step="any" x-model.number="movement.amount" placeholder="{{ __('Amount') }}">
                    <input class="pos-input" x-model="movement.reason" placeholder="{{ __('Reason') }}">
                    <div class="pos-actions"><button class="pos-btn pos-btn-primary" :disabled="!movement.amount" @click="saveMovement()">{{ __('Save') }}</button></div>
                </div>

                <div class="pos-modal" x-show="modal === 'advance'">
                    <h3>{{ __('Request an advance') }}</h3>
                    <input class="pos-input" type="number" min="0" step="any" x-model.number="advance.amount" placeholder="{{ __('Amount') }}">
                    <input class="pos-input" type="number" min="0" step="any" x-model.number="advance.installment" placeholder="{{ __('Monthly installment (optional)') }}">
                    <input class="pos-input" x-model="advance.reason" placeholder="{{ __('Reason') }}">
                    <div class="pos-actions"><button class="pos-btn pos-btn-primary" :disabled="!advance.amount" @click="sendAdvance()">{{ __('Send for approval') }}</button></div>
                    <template x-if="myAdvances.length">
                        <div class="pos-totals">
                            <div class="pos-muted"><span>{{ __('Your latest requests') }}</span></div>
                            <template x-for="(a, i) in myAdvances" :key="i">
                                <div><span x-text="money(a.amount) + ' · ' + (a.at ?? '')"></span><span :class="'pos-advance-' + a.color" x-text="a.status + (a.note ? ' — ' + a.note : '')"></span></div>
                            </template>
                        </div>
                    </template>
                </div>

                <div class="pos-modal" x-show="modal === 'close'">
                    <h3>{{ __('Close shift') }}</h3>
                    <template x-if="summary && summary.blind">
                        <div class="pos-totals">
                            <div class="pos-grand"><span>{{ __('Invoices in this shift') }}</span><span x-text="summary.sales_count"></span></div>
                            <p class="pos-muted">{{ __('Count the cash in the drawer and enter the amount you found.') }}</p>
                        </div>
                    </template>
                    <template x-if="summary && !summary.blind">
                        <div class="pos-totals">
                            <div><span>{{ __('Opening cash') }}</span><span x-text="money(summary.opening)"></span></div>
                            <div><span>{{ __('Cash sales') }}</span><span x-text="money(summary.cash_in_payments)"></span></div>
                            <div><span>{{ __('Cash refunds') }}</span><span x-text="'-' + money(summary.cash_out_payments)"></span></div>
                            <div><span>{{ __('Cash in') }}</span><span x-text="money(summary.cash_in)"></span></div>
                            <div><span>{{ __('Cash out') }}</span><span x-text="'-' + money(summary.cash_out)"></span></div>
                            <div class="pos-grand"><span>{{ __('Expected in drawer') }}</span><span x-text="money(summary.expected_cash)"></span></div>
                            <template x-for="m in summary.by_method" :key="m.name"><div class="pos-muted"><span x-text="m.name"></span><span x-text="money(m.in - m.out)"></span></div></template>
                            <div class="pos-muted"><span>{{ __('Invoices in this shift') }}</span><span x-text="summary.sales_count + ' / ' + money(summary.sales_total)"></span></div>
                        </div>
                    </template>
                    <label>{{ __('Counted cash') }}<input class="pos-input" type="number" min="0" step="any" required x-model.number="closing.counted" :placeholder="summary?.blind ? '{{ __('Amount found in the drawer') }}' : ''"></label>
                    <div class="pos-muted" x-show="summary && !summary.blind" x-text="'{{ __('Difference') }}: ' + money(closing.counted - (summary?.expected_cash ?? 0))"></div>
                    <input class="pos-input" x-model="closing.note" placeholder="{{ __('Note') }}">
                    <div class="pos-actions"><button class="pos-btn pos-btn-danger pos-btn-lg" @click="closeShift()">{{ __('Close shift') }}</button></div>
                </div>
            </div>

            <div class="pos-toast" x-show="toast" x-transition :class="'is-' + toast?.kind">
                <span x-show="toast?.kind === 'ok'">@svg('heroicon-m-check-circle')</span>
                <span x-show="toast?.kind === 'warn'">@svg('heroicon-m-exclamation-triangle')</span>
                <span x-show="toast?.kind === 'error'">@svg('heroicon-m-x-circle')</span>
                <span x-text="toast?.text"></span>
            </div>

            <div class="pos-success" x-show="success" x-transition.opacity @click="success = null">
                <div class="pos-success-card">
                    <svg class="pos-check" viewBox="0 0 52 52"><circle cx="26" cy="26" r="24"/><path d="M15 27l7 7 15-15"/></svg>
                    <h3>{{ __('Sale completed') }}</h3>
                    <div class="pos-muted" x-text="success?.number"></div>
                    <div class="pos-success-total" x-text="money(success?.total)"></div>
                    <div class="pos-success-change" x-show="success?.change > 0">{{ __('Change') }}: <strong x-text="money(success?.change)"></strong></div>
                </div>
            </div>
            <iframe x-ref="printFrame" class="pos-print-frame" title="receipt"></iframe>
        </div>
    @endif
</x-filament-panels::page>
