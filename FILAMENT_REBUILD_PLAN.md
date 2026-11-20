# خطة إعادة بناء نظام POS بـ Filament (PHP 8.4)

> المرجع: UltimatePOS V6.11 (النسخة المعدلة الموجودة في المشروع ده)
> الهدف: نظام POS + ERP خفيف، بنفس الإمكانيات أو أكتر، بمعمارية أنضف وأداء أحسن وقابل للاختبار.

---

## الجزء الأول: تحليل النظام الحالي

### 1. الـ Stack الحالي

| البند | الحالي |
|---|---|
| PHP / Laravel | PHP ^8.0 / Laravel 9.51 |
| الواجهة | Blade + jQuery + Bootstrap (AdminLTE) + Yajra DataTables |
| الصلاحيات | spatie/laravel-permission v5 |
| الـ Modules | nwidart/laravel-modules (21 موديول مفعّلين في `modules_statuses.json` **بس فولدر `Modules/` مش موجود**، يعني الموديولات دي مش متركبة فعليًا) |
| PDF | dompdf + mpdf |
| Excel | maatwebsite/excel |
| API | Laravel Passport (و `routes/api.php` تقريبًا فاضي) |
| بوابات الدفع | Stripe, PayPal, Razorpay, Paystack, Pesapal, MyFatoorah |
| تانيين | Twilio SMS, Pusher, WooCommerce, OpenAI, Activity Log, Backup, Dropbox/S3 |

### 2. حجم الكود ومشاكله

- الـ Business logic كله في **Utils ضخمة** و **Fat Controllers**:
  - `TransactionUtil.php` = **6,569 سطر** (البيع والمرتجعات والدفع والـ FIFO والتقارير والـ Reward points... كله في كلاس واحد)
  - `ReportController.php` = 4,337 سطر، و`SellPosController.php` = 3,033، و`ProductUtil.php` = 2,679، و`ProductController.php` = 2,486
- مفيش اختبارات تقريبًا، ومفيش Enums (كل الأنواع والحالات strings).
- الـ Business الحالي متخزّن في الـ **session** (`session('user.business_id')`)، وده بيصعّب الـ API والـ queues والاختبار.
- أعمدة سحرية: `custom_field_1..20` و `product_custom_field1..20` و `additional_expense_key_1..4`.
- إعدادات كتير محفوظة JSON في عمود text (`pos_settings`، `common_settings`، `email_settings`، `ref_no_prefixes`...).
- المخزون بيتحسب من أكتر من مكان (`variation_location_details.qty_available` + `purchase_lines.quantity_sold/adjusted/returned`)، فبيحصل Mismatch، والدليل إن فيه أوامر زي `RecalculateStock` و `fixVariationStockMisMatch` و `MapPurchaseSell` اتعملت عشان تصلّحه.

### 3. الـ Multi-tenancy
- كل جدول تقريبًا فيه `business_id` (يعني SaaS: أكتر من شركة على نفس الـ DB).
- كل Business ليها **Locations (فروع)**، وكل يوزر بياخد صلاحية على فروع معينة عن طريق permissions اسمها `location.{id}` أو `access_all_locations`.

### 4. قلب النظام: جدول `transactions` (Single Table لكل المستندات)

كل المستندات المالية والمخزنية في جدول واحد، والتمييز بينها بعمود `type`:

| type | المعنى |
|---|---|
| `sell` | فاتورة بيع (POS أو بيع مباشر). `status`: draft / quotation / proforma / final |
| `sell_return` | مرتجع بيع (`return_parent_id` → الفاتورة الأصلية) |
| `sales_order` | أمر بيع |
| `purchase` | فاتورة شراء. `status`: received / pending / ordered |
| `purchase_return` | مرتجع شراء |
| `purchase_order` / `purchase_requisition` | أمر شراء / طلب شراء |
| `opening_stock` | رصيد أول المدة للمخزون |
| `opening_balance` | رصيد أول المدة لعميل/مورد |
| `stock_adjustment` | تسوية مخزون (normal / abnormal) |
| `sell_transfer` + `purchase_transfer` | تحويل بين الفروع (بيتعمل مستندين مربوطين بـ `transfer_parent_id`) |
| `expense` / `expense_refund` | مصروف / استرداد مصروف (فيه recurring) |
| `ledger_discount` | خصم على كشف الحساب (إضافة خاصة بالنسخة دي) |
| `production_purchase`, `hms_booking`, `gym_subscription` | تبع الموديولات |

الأعمدة المهمة: `payment_status` (paid / due / partial)، `shipping_status`، `total_before_tax`، `tax_amount`، `discount_type/amount`، `shipping_charges`، `packing_charge`، `round_off_amount`، `final_total`، `rp_earned/rp_redeemed` (نقاط الولاء)، `res_table_id/res_waiter_id` (للمطاعم)، `is_recurring` و `recur_*` (فواتير ومصروفات متكررة)، `invoice_token` (رابط عام للفاتورة)، `commission_agent`، `selling_price_group_id`، `types_of_service_id`، `exchange_rate`.

### 5. المنتجات والمتغيرات (Products/Variations)

```
products (type: single | variable | combo | modifier)
  └── product_variations (مثلاً: "اللون")
        └── variations (مثلاً: "أحمر": sub_sku, default_purchase_price, dpp_inc_tax,
                        profit_percent, default_sell_price, sell_price_inc_tax, combo_variations)
              ├── variation_location_details (qty_available لكل فرع)
              └── variation_group_prices (سعر لكل Selling Price Group)
```
- المنتج الـ **single** بيبقى ليه variation واحد اسمه `DUMMY`.
- الـ **combo** = باندل من variations تانية (المخزون بينقص من مكوناته).
- الـ **modifier** = إضافات للمطاعم (res_product_modifier_sets).
- **وحدات**: `units` فيها `base_unit_id` و `base_unit_multiplier` (يعني وحدات فرعية زي كرتونة = 12 قطعة)، وفيه كمان `secondary_unit`.
- **تانيين**: brands، categories (فيها sub category، و`categorizables` polymorphic)، `product_locations` (المنتج متاح في أنهي فرع)، `product_racks` (رف/صف/مكان)، warranties، `enable_sr_no` (IMEI/Serial)، `expiry_period`، `alert_quantity`، `barcode_type`، `weight`، `not_for_selling`، `is_inactive`.
- **Variation templates**: قوالب جاهزة للمتغيرات (مقاسات، ألوان).
- **Labels**: طباعة باركود بقوالب (`barcodes`)، ومعاها parsing لباركود الميزان (`parseWeighingBarcode`).

### 6. منطق المخزون والتكلفة (أهم وأعقد جزء)

- `purchase_lines`: كل سطر شراء أو رصيد أول أو تحويل وارد، وفيه `quantity`، `quantity_sold`، `quantity_adjusted`، `quantity_returned`، `mfg_quantity_used`، `lot_number`، `exp_date`، `purchase_price_inc_tax`.
- `transaction_sell_lines_purchase_lines`: **ربط كل سطر بيع بسطور الشراء اللي اتسحب منها** (FIFO أو LIFO حسب `business.accounting_method`). ده اللي بيطلع تكلفة البضاعة المباعة (COGS) والربح الإجمالي.
- `TransactionUtil::mapPurchaseSell()` بيدوّر على purchase lines فيها رصيد متبقي، ويطبّق lot number وتاريخ الصلاحية (`stop_selling_before`)، ويسمح بالبيع على المكشوف (`allow_overselling`).
- كل عملية تعديل أو مسح فاتورة بتعمل إعادة ربط (`adjustMappingPurchaseSell`, `adjustMappingPurchaseSellAfterEditingPurchase`)، ودي مصدر أغلب الـ bugs.

### 7. المبيعات و الـ POS

- **POS screen** (`SellPosController` + `sale_pos/create.blade.php`): بحث منتج/باركود، منتجات مميزة لكل فرع، اختيار عميل (والـ Walk-in customer الافتراضي)، Selling Price Group، خصم على السطر وعلى الفاتورة، ضريبة على السطر وعلى الفاتورة، شحن، Packing charge، تقريب، Modifiers، Serial/IMEI، Lot، اختيار Service Staff، Types of Service (Dine-in / Takeaway / Delivery بأسعار مختلفة)، نقاط الولاء، حد ائتمان العميل (`isCustomerCreditLimitExeeded`).
- **أنواع الإنهاء**: Draft، Quotation، Proforma، Suspend، Express Checkout (Cash)، Card، Multiple Pay، Credit Sale.
- **Cash denominations** (فئات العملة) عند الدفع وعند قفل الدرج.
- **Customer display** (شاشة للعميل)، وآخر المعاملات، و keyboard shortcuts قابلة للتعديل.
- **صلاحيات دقيقة**: `edit_product_price_from_pos_screen`، `edit_product_discount_from_pos_screen`، `edit_pos_payment`، `view_own_sell_only`، `view_paid/due/partial/overdue_sells_only`...
- **Sales orders** وتحويلها لفواتير (`so_quantity_invoiced`)، والـ Subscriptions (فواتير متكررة)، والـ Duplicate، و Copy quotation، والـ Shipments (`shipping_status` + صلاحيات شحن)، وتعديل الشحن.
- **المرتجعات**: مرتجع من فاتورة (`quantity_returned` على السطر)، وفيه validate للفاتورة برقمها.
- **Import sales** من Excel مع revert بالـ batch.
- **Sales commission agents** وعمولاتهم.
- **روابط عامة**: `/invoice/{token}`، `/quote/{token}`، `/pay/{token}` (دفع أونلاين).

### 8. المشتريات
- Purchase Requisition ← Purchase Order ← Purchase (مع `purchase_order_ids` و `po_quantity_purchased`).
- Purchase status (received/pending/ordered)، ومصاريف إضافية (4 حقول)، وعملة مختلفة وسعر صرف، وتعديل سعر البيع من شاشة الشراء (`enable_editing_product_from_purchase`)، واستيراد سطور الشراء من Excel.
- مرتجع شراء (مرتبط بفاتورة أو Combined مستقل).

### 9. المدفوعات والحسابات
- `transaction_payments`: كل دفعة، وفيها `method` (cash, card, cheque, bank_transfer, custom_pay_1..7, advance)، و `payment_for` (contact)، و `parent_id` (دفعة مجمعة على كذا فاتورة عن طريق `payContact` / `pay-contact-due`)، و `is_return`، و `is_advance`.
- رصيد العميل المقدم بيتخزن في `contacts.balance`.
- **Accounting بسيط** (مش قيد مزدوج): `accounts` + `account_types` + `account_transactions` (credit/debit)، وفيه fund transfer و deposit، وBalance sheet و Trial balance و Cash flow و Payment account report. الربط بيتم بالـ Events (`TransactionPaymentAdded` ← Listener `AddAccountTransaction`).
- **Vouchers** (إضافة خاصة بالنسخة دي): سندات قبض وصرف (`VoucherController`) وطباعة رسمية بالتفقيط العربي (`numToWord`). الخطة الأصلية موجودة في `vouchers_plan.md`.
- **Ledger discount** (إضافة خاصة): خصم على حساب العميل/المورد.

### 10. الـ Cash Register (الدرج / الوردية)
- `cash_registers` (open/close، `closing_amount`، `total_card_slips`، `total_cheques`، `denominations`) + `cash_register_transactions` (sell/refund/expense، credit/debit، pay_method).
- لازم يكون الدرج مفتوح عشان تبيع من الـ POS، وفيه تقرير الدرج (`register_report`).

### 11. العملاء والموردين (Contacts)
- `type`: customer / supplier / both. وفيه customer groups (خصم % أو selling price group)، pay terms، credit limit، رصيد افتتاحي، Ledger (كشف حساب) وإرساله بالإيميل، خريطة العملاء، استيراد، reward points، `contact_status`، و `user_contact_access` (يوزر يشوف عملاء معينين بس).

### 12. المطاعم (Restaurant)
- Tables، Bookings، Kitchen screen (mark as cooked)، Orders screen للـ waiter (mark as served)، Service staff مع timer، Modifiers، طباعة طلب المطبخ لكل سطر (`res_line_order_status`).

### 13. التقارير (28+ تقرير)
Profit/Loss، Purchase & Sell، Tax report (و GST)، Contacts (Customer/Supplier)، Customer groups، Stock report، Stock details، Stock value (by sell/purchase price)، Stock adjustment، Stock expiry، Lot report، Trending products، Items report، Product purchase / Product sell (grouped / with purchase)، Purchase payment / Sell payment، Expense report، Register report، Sales representative (sell/expense/commission)، Service staff، Table report، Activity log، Dashboard (totals + charts + alerts + dues).

### 14. الإعدادات
- **Business settings**: العملة ودقتها، المنطقة الزمنية، تنسيق التاريخ والوقت، السنة المالية، طريقة التكلفة (FIFO/LIFO)، الـ prefixes لكل نوع مستند، تفعيل/تعطيل (brand، category، sub category، racks، lot، expiry، sub units، inline tax، purchase status، reward points)، custom labels، weighing scale، keyboard shortcuts، email/SMS settings، الموديولات.
- **Invoice layouts** (أكتر من 100 خيار للعرض والـ labels، وفيه QR / ZATCA)، و **Invoice schemes** (ترقيم)، و **Printers** (receipt printer: browser أو server)، و **Barcode settings**.
- **Notification templates** (Email/SMS/WhatsApp) مع tags، وتذكير الدفع التلقائي.
- **Tax rates** و **Group taxes**.
- **Discounts** (خصومات مجدولة على منتجات/براند/كاتيجوري لكل فرع).
- **Dashboard configurator**، و **Backup**، و **Documents & Notes**.

### 15. الأوامر والـ Cron
`RecurringInvoice`، `RecurringExpense`، `AutoSendPaymentReminder`، `UpdateRewardPoints`، `RecalculateStock`، `MapPurchaseSell`، `MapFifoStock`، `MapReturns`، `CreateDummyBusiness`.

**أوامر استيراد خاصة بالنسخة دي** من نظام POS قديم (البيانات في `extracted_data/*.csv` + backup اسمه `newRussiano-*.bck` حجمه ~611MB): `import:invoices`، `ImportProducts`، `ImportContacts`، `ImportStores`، `ImportTransfers`، `ImportExpenses`، `ImportCategories`، `UpdateOldBarcodes`. **لازم ناخد بالنا إن العميل ده عنده داتا حقيقية محتاجة تتنقل.**

---

## الجزء التاني: المعمارية الجديدة

### 1. الـ Stack المقترح

| البند | الجديد | السبب |
|---|---|---|
| PHP | **8.4** | property hooks، asymmetric visibility، `array_find`... |
| Laravel | أحدث نسخة stable (12.x أو أحدث) | |
| Admin UI | **Filament** (أحدث major stable، v4 أو أحدث) + Livewire + Alpine + Tailwind | CRUD وTables وForms وFilters وWidgets جاهزين |
| POS Screen | **Custom Livewire page** جوه Filament panel مستقل (مش Resource) | الـ POS محتاج سرعة وتحكم كامل |
| Realtime | **Laravel Reverb** | شاشة المطبخ، شاشة العميل، الإشعارات |
| Queues | Redis + **Horizon** | PDF، إيميل، SMS، تقارير تقيلة، sync |
| Tenancy | Filament Tenancy (`Business` = tenant) + Global Scope | بدل الـ session |
| Permissions | spatie/laravel-permission + **Filament Shield** (أو Policies يدوي) | |
| Settings | **spatie/laravel-settings** (typed settings classes) | بدل JSON في عمود text |
| Media | spatie/laravel-medialibrary | صور المنتجات والمرفقات |
| Audit | spatie/laravel-activitylog | |
| Money | **brick/money** أو integer minor units + Value Object | منع أخطاء التقريب |
| PDF | **mPDF** (عشان العربي/RTL) أو spatie/laravel-pdf (Browsershot) | |
| Import/Export | Filament Importers/Exporters (built-in) | |
| Search | Laravel Scout + Meilisearch (أو MySQL fulltext كبداية) | بحث المنتجات في الـ POS |
| API | **Sanctum** + API Resources (versioned `/api/v1`) | موبايل / تكامل |
| Testing | **Pest** + Larastan (level 6+) + Pint + Rector | |
| Backup | spatie/laravel-backup | |

### 2. الـ Panels

| Panel | المسار | لمين |
|---|---|---|
| `admin` | `/admin` | أصحاب البيزنس والمديرين والمحاسبين (كل الـ Resources والتقارير والإعدادات) |
| `pos` | `/pos` | الكاشير: شاشة full-screen، فتح/قفل الدرج، الفواتير المعلقة |
| `kitchen` | `/kitchen` | المطبخ والـ waiters (realtime) |
| `superadmin` | `/superadmin` | لو هنبيعه SaaS: الاشتراكات والباقات والـ businesses |
| (اختياري) `portal` | `/portal` | العميل يشوف فواتيره وكشف حسابه ويدفع |

### 3. تنظيم الكود (Domain-oriented)

```
app/
  Domain/
    Catalog/        (Product, Variation, Unit, Brand, Category, PriceGroup, Barcode)
    Inventory/      (StockMovement, StockLayer, Adjustment, Transfer, Warehouse)
    Sales/          (Sale, SaleItem, Quotation, SalesOrder, Return, Shipment)
    Purchasing/     (Requisition, PurchaseOrder, Purchase, PurchaseReturn)
    Payments/       (Payment, PaymentAllocation, PaymentMethod, Gateway)
    Finance/        (Account, JournalEntry, JournalLine, Expense, Voucher)
    CashManagement/ (CashRegister, CashSession, CashMovement)
    CRM/            (Contact, CustomerGroup, Loyalty)
    Restaurant/     (Table, Booking, KitchenTicket, Modifier)
    Tenancy/        (Business, Location, Subscription)
    Reporting/      (Query classes لكل تقرير)
    Each domain → Models/ Actions/ DTOs/ Enums/ Events/ Policies/ QueryBuilders/
  Filament/
    Admin/Resources/...   Pos/Pages/...   Kitchen/Pages/...
```
- **Actions** (كلاس واحد لكل عملية): `CreateSale`، `FinalizeSale`، `ReturnSale`، `ReceivePurchase`، `PostStockMovement`، `AllocatePayment`، `CloseCashSession`... كل Action جوه `DB::transaction()` وبيطلّع Domain Event.
- **Enums** (PHP backed enums) لكل الأنواع والحالات: `SaleStatus`، `PaymentStatus`، `PaymentMethod`، `StockMovementType`، `CostingMethod`، `DiscountType`... وتعمل `HasLabel`/`HasColor`/`HasIcon` عشان Filament يعرضها لوحده.
- Filament Resources تبقى **رفيعة**: بتنادي على Actions بس، من غير أي business logic جواها.

### 4. إعادة تصميم قاعدة البيانات (أهم تحسين)

**القرار:** نفصل `transactions` لجداول لكل مستند، ونعمل **Stock Ledger** و **Double-entry Journal**.

```
-- المبيعات
sales (id, business_id, location_id, contact_id, number, status, type[pos|direct],
       sale_date, subtotal, discount_*, tax_total, shipping, packing, round_off, total,
       paid_total, due_total, payment_status, price_group_id, service_type_id,
       table_id, waiter_id, commission_agent_id, cash_session_id, token, meta json, ...)
sale_items (sale_id, variation_id, unit_id, qty, base_qty, unit_price, discount_*,
            tax_rate_id, tax_amount, line_total, cost_total, lot_id, serials json, parent_item_id[modifier/combo])
quotations, sales_orders (+ items)    -- أو status على sales حسب القرار
sale_returns (+ items)  مربوطة بـ sale_items

-- المشتريات
purchase_requisitions, purchase_orders, purchases, purchase_returns (+ items لكل واحد)

-- المخزون
stock_movements (business_id, location_id, variation_id, qty(+/-), unit_cost,
                 source_type, source_id (morph: sale_item/purchase_item/adjustment/transfer...),
                 lot_id, moved_at)            ← مصدر الحقيقة الوحيد للكميات
stock_levels (location_id, variation_id, qty_on_hand, qty_reserved, avg_cost)  ← cache بيتحدث في نفس الـ DB transaction
stock_layers (FIFO layers: remaining_qty, unit_cost, lot, expiry) + stock_layer_consumptions
lots (number, mfg_date, exp_date)   serials (serial/IMEI, status)
stock_adjustments, stock_transfers (+ items، وحالة: pending/in_transit/completed)
stock_counts (جرد فعلي)   ← جديد

-- المدفوعات والمالية
payments (business_id, contact_id, direction[in|out], method_id, account_id, amount,
          paid_at, reference, cash_session_id, meta)
payment_allocations (payment_id, payable_type, payable_id, amount)   ← دفعة واحدة على كذا فاتورة
accounts (شجرة حسابات: parent_id, code, type[asset|liability|equity|income|expense])
journal_entries (date, source morph, memo) + journal_lines (account_id, debit, credit, contact_id, location_id)
expenses, expense_categories, vouchers (receipt/payment)

-- الدرج
cash_registers (جهاز/درج لكل فرع)   cash_sessions (open/close, opening_float, expected, counted, difference, denominations json)
cash_movements (cash in/out يدوي)
```

**ليه؟**
- الكمية = `SUM(stock_movements.qty)`، ومفيش Mismatch تاني، و`stock_levels` مجرد cache تقدر تعيد بناءه.
- الـ FIFO layers بتتحسب **مرة واحدة** وقت الحركة، وتعديل فاتورة = عكس الحركة وتسجيل حركة جديدة (reversal) بدل إعادة الربط المعقدة.
- القيد المزدوج بيطلّع Trial Balance و Balance Sheet و P&L **صح محاسبيًا**، وكل بيع وشراء ودفع ومصروف بيعمل قيد أوتوماتيك عن طريق `PostingRules`.
- المبالغ: `decimal(20,4)` للكميات والأسعار، والحسابات تتم بـ Money VO.
- كل حاجة تختص بـ `business_id` + Global Scope + index مركّب `(business_id, location_id, date)`.
- **Custom fields**: جدول `custom_field_definitions` + عمود `custom_fields json`، بدل 20 عمود.

### 5. الـ Tenancy والأمان
- `Business` هو الـ tenant في Filament (`->tenant(Business::class)`)، وكل Model فيه trait `BelongsToBusiness` (Global Scope + auto-fill).
- **الفروع**: علاقة `location_user` بدل permission لكل فرع، وفي الـ POS بنختار الفرع والدرج.
- Policies لكل Resource، والـ permissions الدقيقة القديمة (حوالي 150 permission) تتنقل كـ permissions بأسماء منظمة (`sales.view_own`، `pos.edit_price`...).
- Rate limiting، و 2FA (Filament MFA)، و PIN سريع للكاشير وتبديل اليوزر على نفس الجهاز.

---

## الجزء التالت: الموديولات والـ Features (مع الـ mapping لـ Filament)

### M0: الأساس (Foundation)
- [ ] مشروع Laravel + PHP 8.4، Pint/Larastan/Pest/Rector، و CI (GitHub Actions)
- [ ] Panels: admin / pos / kitchen، ودعم RTL والعربي من الأول (ترجمات `lang/ar` و `lang/en`)
- [ ] Tenancy (Business/Location)، والتسجيل (Register business wizard)، و Onboarding wizard (عملة، ضريبة، فرع، درج)
- [ ] Users / Roles / Permissions (Shield)، والدخول كيوزر تاني (impersonate)
- [ ] Settings classes: `GeneralSettings`، `PosSettings`، `SaleSettings`، `PurchaseSettings`، `InventorySettings`، `TaxSettings`، `LoyaltySettings`، `NotificationSettings`، وصفحة Filament لكل واحدة
- [ ] Currencies، وترقيم المستندات (`DocumentSequence` لكل نوع × فرع × سنة، مع lock)
- [ ] Activity log viewer، والـ Backup

### M1: الكتالوج (Catalog)
- [ ] `ProductResource`: Single / Variable / Combo / Service (من غير مخزون) / Modifier، و Wizard أو Tabs للفورم
- [ ] Variation templates، و Repeater للـ variations مع SKU تلقائي
- [ ] Units + Sub-units (conversion) + Secondary unit
- [ ] Brands، و Categories (شجرة)، و Tax rates + Group taxes، و Warranties، و Racks
- [ ] Selling price groups + أسعار لكل variation (Relation manager + bulk price editor)
- [ ] Discounts المجدولة (Rules: منتج/براند/كاتيجوري/فرع/price group)
- [ ] Barcode: توليد، أنواع (EAN13/C128...)، باركود الميزان، طباعة Labels بقوالب (Filament page + preview)
- [ ] Import/Export منتجات (Filament Importer)، و Bulk edit، و Mass activate/deactivate
- [ ] صفحة View Product: المخزون لكل فرع، Stock history، الحركة، آخر سعر شراء

### M2: المخزون (Inventory)
- [ ] `StockLedger` service: `post()` / `reverse()`، و FIFO/LIFO/Weighted Average (اختيار من الإعدادات)
- [ ] Opening stock (يدوي + import)
- [ ] Stock adjustments (normal/abnormal + سبب + recovered amount)
- [ ] Stock transfers بين الفروع (حالات + طباعة + استلام جزئي)
- [ ] Lots + Expiry (منع البيع قبل الانتهاء بـ X يوم، وتقرير الصلاحية، وإعدام المنتهي)
- [ ] Serial/IMEI tracking
- [ ] **جديد**: جرد فعلي (Stock count) مع فروقات وتسوية أوتوماتيك
- [ ] **جديد**: Reorder point و Auto-suggest لأمر الشراء
- [ ] Alerts: كمية أقل من الحد، وقرب الانتهاء (Notification + Widget)

### M3: العملاء والموردين (CRM)
- [ ] `ContactResource` (customer/supplier/both)، و Customer groups، و Pay terms، و Credit limit
- [ ] كشف الحساب (Ledger) مع رصيد افتتاحي وطباعة PDF وإرسال إيميل/واتساب
- [ ] رصيد مقدم (Advance)، و Ledger discount
- [ ] Loyalty / Reward points (كسب، استبدال، انتهاء)
- [ ] Import contacts، والخريطة، وصلاحية "يشوف عملاءه بس"
- [ ] **جديد**: تاريخ العميل في الـ POS (آخر سعر بيع ليه، المديونية)

### M4: الـ POS (أهم شاشة، تتبني Custom)
- [ ] Livewire page full-screen + Alpine للـ cart (الـ state في الـ client والـ sync عند الدفع عشان السرعة)
- [ ] بحث فوري (اسم/SKU/باركود)، و scanner listener، وباركود الميزان، وGrid للمنتجات بالكاتيجوري، والمنتجات المميزة
- [ ] اختيار العميل + إضافة سريعة، و Price group، و Service type، والترابيزة، والـ waiter، والـ commission agent
- [ ] خصم وضريبة على السطر وعلى الفاتورة حسب الصلاحية، وتعديل السعر حسب الصلاحية (مع PIN مدير للـ override)
- [ ] Modifiers، و Combo، و Serial، و Lot، و Sub-unit
- [ ] الدفع: كاش (مع حساب الباقي و denominations)، كارت، multi-pay، آجل (مع credit limit check)، رصيد مقدم، استبدال نقاط
- [ ] Draft، و Quotation، و Suspend/Hold، و Recall، و Recent transactions، و Edit (بصلاحية)، و Return سريع من الـ POS
- [ ] Keyboard shortcuts قابلة للتعديل
- [ ] الطباعة: Thermal 58/80mm (HTML + `window.print`)، و ESC/POS عن طريق **QZ Tray** أو local print agent، وفتح درج الكاش
- [ ] Customer display (شاشة تانية realtime بالـ Reverb أو BroadcastChannel)
- [ ] **جديد**: PWA + **Offline mode** (IndexedDB queue + sync)، دي ميزة قوية جدًا قدام UltimatePOS
- [ ] **جديد**: Touch-friendly للتابلت

### M5: المبيعات (Sales back-office)
- [ ] `SaleResource` (كل الفواتير)، مع filters (فرع، عميل، حالة دفع، شحن، تاريخ، كاشير، service staff)
- [ ] Add/Edit sale مباشر (فورم كامل)، و Duplicate، و Convert (quotation ← sale)
- [ ] Quotations، و Proforma، و Drafts
- [ ] Sales Orders + تحويل جزئي لفواتير
- [ ] Sell returns (من فاتورة، أو مستقل، و Credit note)
- [ ] Shipments (حالات + delivery person + صلاحيات)
- [ ] Recurring invoices / Subscriptions (Scheduler)
- [ ] Invoice public link + Online payment link (Stripe/PayPal/MyFatoorah/Paymob...)
- [ ] Import sales (مع revert batch)
- [ ] Commission agents

### M6: المشتريات (Purchasing)
- [ ] Purchase requisition ← Purchase order ← Purchase (تحويل جزئي)
- [ ] Purchase بعملة أجنبية + سعر صرف، ومصاريف إضافية بتتوزع على التكلفة (**جديد: Landed cost distribution**)
- [ ] تحديث سعر البيع من شاشة الشراء (هامش ربح %)
- [ ] Purchase returns (من فاتورة / مستقل)
- [ ] Import purchase lines

### M7: المدفوعات والمالية (Finance)
- [ ] Payments مع Allocations (دفعة على كذا فاتورة، أو Pay contact due)
- [ ] Payment methods ديناميكية (بدل `custom_pay_1..7`)
- [ ] شجرة الحسابات (Chart of Accounts) افتراضية + قابلة للتعديل
- [ ] Journal تلقائي (Posting rules) + قيود يدوية
- [ ] Bank/Cash accounts، و Fund transfer، و Deposit، و Reconciliation (**جديد**)
- [ ] Expenses + categories/sub + recurring + expense for user
- [ ] **سندات القبض والصرف** (Vouchers) بطباعة رسمية وتفقيط عربي (نفس متطلبات `vouchers_plan.md`)
- [ ] التقارير: Trial balance، Balance sheet، P&L، Cash flow، Account statement

### M8: الدرج (Cash Management)
- [ ] فتح وردية (opening float)، و Cash in/out، وقفل الوردية (counted vs expected + denominations + كروت + شيكات)
- [ ] X-Report / Z-Report، وتقرير الأدراج
- [ ] صلاحيات: `view_cash_register`، `close_cash_register`، ومدير يقفل وردية حد تاني

### M9: المطاعم (Restaurant)
- [ ] Tables (مع floor plan مرئي **جديد**)، و Bookings (calendar)
- [ ] Kitchen Display System (KDS) realtime، وحالات السطر، ووقت التحضير
- [ ] Waiter orders screen، و Service staff timer، و PIN
- [ ] طباعة تذاكر المطبخ لكل printer/station (**جديد: kitchen stations**)
- [ ] Split / Merge bill (**جديد**)

### M10: التقارير والداشبورد
- [ ] Dashboard: Widgets (مبيعات اليوم/الشهر، مشتريات، مصروفات، ربح، مديونيات، stock alerts، charts)، مع Dashboard configurator
- [ ] كل التقارير الموجودة في القسم 13 فوق، ولكل تقرير: Query class + Filament Page + Table + Export (Excel/PDF) + Filters
- [ ] تقارير تقيلة تتعمل في Queue وتتبعت كـ Notification
- [ ] **جديد**: ABC analysis، Dead stock، Hourly sales heatmap، Cashier performance، Margin by category

### M11: الإشعارات والتكاملات
- [ ] Notification templates (Email/SMS/WhatsApp) + tags، وتذكير الدفع التلقائي
- [ ] SMS gateways (Twilio + generic HTTP)، و WhatsApp (Cloud API)
- [ ] بوابات الدفع (Contracts + Drivers)
- [ ] WooCommerce / Ecommerce sync (مرحلة لاحقة)
- [ ] **ZATCA (السعودية) / ETA الفاتورة الإلكترونية (مصر)**: driver interface عشان نضيف أي دولة
- [ ] REST API (Sanctum) لتطبيق موبايل

### M12: Superadmin / SaaS (لو هيتباع اشتراكات)
- [ ] Packages (حدود: فروع، يوزرز، منتجات، فواتير)، و Subscriptions، و Quota middleware، والدفع

### M13: موديولات إضافية (بعد الـ MVP، حسب الأولوية)
HRM/Essentials (حضور، مرتبات)، Repair (صيانة)، Manufacturing (تصنيع ووصفات)، CRM متقدم، Project، Asset management، Gym، HMS (فنادق)، AI Assistant.
> ملحوظة: الموديولات دي مفعّلة في `modules_statuses.json` بس الكود بتاعها مش موجود في المشروع، فلازم نتأكد من العميل هو بيستخدم إيه فعلًا.

---

## الجزء الرابع: نقل البيانات (Data Migration)

1. أوامر `artisan migrate:legacy {--business=} {--dry-run} {--chunk=}` بتقرا من اتصال DB تاني (`legacy`) هو قاعدة UltimatePOS الحالية.
2. الترتيب: Business/Locations ← Users/Roles ← Units/Brands/Categories/Taxes ← Products/Variations/Prices ← Contacts ← Opening balances ← Purchases ← Sales ← Returns ← Transfers/Adjustments ← Payments ← Expenses ← Cash registers.
3. المخزون: نحسب `stock_movements` من التاريخ كله، أو (الأسرع) **opening balance لكل variation × location في تاريخ القطع** من `variation_location_details` + آخر تكلفة.
4. جدول `legacy_id_map` (old_table, old_id, new_id) عشان الربط وإمكانية إعادة التشغيل (idempotent).
5. Reconciliation script: مقارنة إجمالي المبيعات والمديونيات والمخزون بين القديم والجديد لكل فرع وشهر.
6. البيانات اللي اتنقلت من النظام القديم (Russiano CSVs) موجودة أصلًا جوه UltimatePOS، فهننقلها من هناك مباشرة.

---

## الجزء الخامس: المراحل والجدول الزمني (تقديري لفريق 2-3 مطورين)

| المرحلة | المحتوى | المدة |
|---|---|---|
| **0. Discovery** | مراجعة مع العميل: الموديولات المستخدمة فعلًا، الدولة (ضرايب/فاتورة إلكترونية)، الأجهزة (طابعات/ميزان)، Offline مطلوب ولا لأ؟ | 1 أسبوع |
| **1. Foundation** | M0 + تصميم DB كامل + Enums + Money + Sequences + CI | 2-3 أسابيع |
| **2. Catalog + Inventory core** | M1 + StockLedger + Opening stock + Adjustments + Transfers | 4 أسابيع |
| **3. CRM + Purchasing** | M3 + M6 + Payments الأساسية | 3 أسابيع |
| **4. POS + Sales + Cash** | M4 + M5 + M8 + الطباعة | 5-6 أسابيع |
| **5. Finance** | M7 (Journal، الحسابات، المصروفات، السندات) | 3 أسابيع |
| **6. Reports + Dashboard** | M10 | 3 أسابيع |
| **🚀 MVP / UAT** | نقل بيانات تجريبي + اختبار مع العميل على فرع واحد | 2 أسابيع |
| **7. Restaurant** | M9 | 3 أسابيع |
| **8. Notifications/Integrations/API** | M11 | 3 أسابيع |
| **9. Offline POS (PWA)** | M4 offline | 3 أسابيع |
| **10. SaaS + Modules** | M12 + M13 حسب الأولوية | مستمر |

**الـ MVP ≈ 4-5 شهور.**

### تعريف الـ "Done" لكل Feature
- Action + Unit/Feature tests (Pest)، وخصوصًا: الـ FIFO costing، والمرتجعات، والـ allocations، والقيود (لازم المدين = الدائن دايمًا)، وقفل الدرج
- Policy + Permission
- ترجمة ar/en + RTL
- Activity log
- Index مناسب + اختبار أداء (مثلًا 1M sale_items)

---

## الجزء السادس: التحسينات اللي هتخلّيه أحسن من UltimatePOS

1. **Stock ledger** = مفيش stock mismatch تاني، وكل كمية ليها مصدر.
2. **محاسبة بالقيد المزدوج** حقيقية بدل الـ account_transactions البسيطة.
3. **POS أسرع** (client-side cart) + **Offline** + Touch/Tablet.
4. **معمارية قابلة للاختبار** (Actions/Enums/DTOs) بدل Utils فيها 6000 سطر.
5. **Typed settings** و **Custom fields** ديناميكية.
6. **Realtime** (المطبخ، شاشة العميل، الإشعارات) بالـ Reverb.
7. **Queued reports & PDFs**: السيستم مش هيهنج مع الداتا الكبيرة.
8. **Landed cost**، **جرد فعلي**، **Reorder suggestions**، **Bank reconciliation**، **Split/Merge bill**، **Kitchen stations**.
9. **E-invoicing drivers** (ZATCA / ETA مصر).
10. **API نضيف** لتطبيق موبايل.
11. **UI حديث** بـ Filament (Dark mode، Global search، Notifications، Bulk actions).
12. **أمان أعلى**: 2FA، Policies في كل مكان، Audit كامل، Rate limit.

---

## الجزء السابع: قرارات محتاجة تتاخد قبل البدء

1. **SaaS ولا Single-tenant؟** (بتأثر على Superadmin والـ tenancy: نفس الـ DB ولا DB لكل عميل)
2. **الدولة المستهدفة** للضرايب والفاتورة الإلكترونية (مصر ETA / السعودية ZATCA / غيرهم)
3. **Offline POS ضمن الـ MVP ولا بعده؟**
4. **طريقة الطباعة**: Browser print بس، ولا QZ Tray/ESC-POS (فتح الدرج والقص)
5. **التكلفة**: FIFO بس، ولا FIFO + Weighted Average (منتشر في مصر)
6. **أنهي موديولات من الـ 21** العميل محتاجها فعلًا
7. **Filament version**: نثبّت على أحدث major stable وقت البدء ونقفل عليه
8. **هل هننقل الداتا التاريخية كلها** ولا أرصدة افتتاحية في تاريخ القطع بس؟

---

## ملحق: Mapping سريع من القديم للجديد

| القديم (UltimatePOS) | الجديد |
|---|---|
| `transactions` (type=sell) | `sales` + `sale_items` |
| `transactions` (type=purchase) + `purchase_lines` | `purchases` + `purchase_items` + `stock_layers` |
| `variation_location_details` | `stock_levels` (cache) ← `stock_movements` |
| `transaction_sell_lines_purchase_lines` | `stock_layer_consumptions` |
| `transaction_payments` (parent_id) | `payments` + `payment_allocations` |
| `accounts` / `account_transactions` | `accounts` (tree) + `journal_entries/lines` |
| `cash_registers` / `cash_register_transactions` | `cash_registers` + `cash_sessions` + `cash_movements` |
| `business.pos_settings` JSON | `PosSettings` (spatie/settings) |
| `custom_field_1..N` | `custom_fields` JSON + definitions |
| `permission: location.{id}` | `location_user` pivot |
| `session('user.business_id')` | `Filament::getTenant()` + Global Scope |
| `TransactionUtil` (6.5k سطر) | Actions في `Domain/Sales`, `Domain/Inventory`, `Domain/Payments`... |
| Yajra DataTables | Filament Tables |
| Blade + jQuery POS | Livewire + Alpine POS page |
