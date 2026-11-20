# خطة الـ Migrations: قاعدة بيانات newpos

> مبنية على تحليل UltimatePOS V6.11 (حوالي 60 جدول و300 ملف migration) وعلى [FILAMENT_REBUILD_PLAN.md](FILAMENT_REBUILD_PLAN.md).
> القرارات اللي اتاخدت: **نسخة لكل عميل (Single-tenant)**، و**مفيش فاتورة إلكترونية**.

---

## 0. القواعد العامة (Conventions)

| القاعدة | التطبيق |
|---|---|
| **مفيش `business_id`** | كل عميل ليه نسخة وقاعدة بيانات لوحده، فمفيش داعي لـ tenant column ولا Global Scope. ده بيبسّط الـ queries والـ indexes، وبيخلّيها أسرع. |
| **الفروع** | `location_id` موجود في كل مستند وكل حركة مخزون. |
| **IDs** | `id()` (bigint). ومعاه `ulid('public_token')` للحاجات اللي ليها رابط عام بس (الفاتورة وعرض السعر). |
| **الفلوس** | `decimal(18, 4)` لكل المبالغ والأسعار، والحسابات بتتم في الكود بـ Money VO. |
| **الكميات** | `decimal(18, 4)`، ولكل سطر `base_qty` بالوحدة الأساسية، علشان الوحدات الفرعية زي الكرتونة. |
| **Enums** | عمود `string(30)` بيتربط بـ PHP backed enum بالـ cast، بدل `enum()` في MySQL، علشان إضافة قيمة جديدة متحتاجش migration. |
| **Foreign keys** | `foreignId()->constrained()`. المستندات المالية بتاخد `restrictOnDelete()`، والسطور بتاخد `cascadeOnDelete()` مع المستند بتاعها. |
| **الحذف** | البيانات الأساسية (منتجات وعملاء ويوزرز) فيها `softDeletes()`. أما **المستندات المالية فمبتتمسحش**: بتتلغي (`voided_at` + `void_reason`) ويتعمل قيد عكسي وحركة مخزون عكسية. |
| **Audit** | `created_by` و `updated_by` في المستندات، و activity log لكل حاجة. |
| **Custom fields** | عمود `custom_fields` من نوع `json` مع جدول definitions، بدل `custom_field_1..20`. |
| **الإعدادات** | `spatie/laravel-settings` (جدول `settings`) بدل أعمدة JSON في جدول business. |
| **التواريخ** | `dateTime` للمستند (`sale_date` مثلًا)، ومنفصل عن `created_at`. |
| **DB** | MySQL 8 أو MariaDB 10.6+، أو PostgreSQL. أي constraint مهم بيتطبق في الكود، وكمان كـ CHECK في الـ DB لو متاح. |

**ترتيب الملفات:** كل مجموعة جداول بتاخد بادئة تاريخ بنفس ترتيب الأقسام تحت، علشان الـ FKs تتعمل بالترتيب الصح.

---

## 1. الأساس (Foundation)

### 1.1 جداول Laravel والـ packages
- `users`، `password_reset_tokens`، `sessions`، `cache`، `jobs`، `job_batches`، `failed_jobs` (دول الـ defaults)
- `permissions`، `roles`، `model_has_*`، `role_has_permissions` (spatie/permission)
- `settings` (spatie/laravel-settings)
- `activity_log` (spatie/activitylog)
- `media` (spatie/medialibrary)
- `notifications` (Filament database notifications)

### 1.2 `users` (هنعدّل على الـ default)
```
name, username (unique, nullable), email (unique, nullable), password
pin (string nullable, hashed)          ← دخول سريع للكاشير
language (string 5, default 'ar')
is_active (bool), allow_login (bool)
is_service_staff (bool), is_commission_agent (bool), commission_percent (decimal 5,2)
max_discount_percent (decimal 5,2 nullable)  ← أقصى خصم مسموح للكاشير
phone, custom_fields json, softDeletes, timestamps
```

### 1.3 `currencies`
```
code (char 3, unique), name, symbol, precision (tinyint), symbol_position, thousand_separator, decimal_separator
```
> عملة النظام الأساسية بتتحدد في `GeneralSettings`. الجدول ده محتاجينه علشان المشتريات اللي بعملة أجنبية.

### 1.4 `locations` (الفروع)
```
code (unique), name, address, city, phone, email
is_active, default_price_group_id (FK nullable → price_groups, بيتضاف بعد ما الجدول يتعمل)
invoice_layout_id (nullable), receipt_layout_id (nullable)
custom_fields json, softDeletes, timestamps
```
- `location_user` (location_id, user_id) primary(location_id, user_id). ده بديل الـ permission القديمة `location.{id}`.

### 1.5 `document_sequences` (ترقيم المستندات)
```
document_type (string: sale, sale_return, purchase, quotation, voucher_receipt ...)
location_id (nullable), year (smallint nullable)
prefix, next_number (unsigned bigint), padding (tinyint)
unique(document_type, location_id, year)
```
> الرقم بيتسحب بـ `lockForUpdate()` جوه نفس الـ transaction، فمستحيل يتكرر.

### 1.6 `custom_field_definitions`
```
entity (product|contact|sale|purchase|location|user), key, label, type (text|number|date|select|bool)
options json, is_required, sort, unique(entity, key)
```

### 1.7 `notes` (Documents & Notes)
```
notable_type, notable_id (morph), title, body, is_private, created_by, timestamps
```
> المرفقات بتتحفظ عن طريق medialibrary.

---

## 2. الكتالوج (Catalog)

| # | الجدول | أهم الأعمدة |
|---|---|---|
| 2.1 | `units` | name, short_name, allow_decimal, base_unit_id (self FK nullable), base_multiplier (decimal 18,4). **مثال: كرتونة = 12 × قطعة** |
| 2.2 | `brands` | name (unique), description, softDeletes |
| 2.3 | `categories` | name, code, parent_id (self) ← شجرة بدل category و sub_category، softDeletes |
| 2.4 | `tax_rates` | name, rate (decimal 7,4), is_group (bool), is_active |
| 2.5 | `tax_rate_group` | group_id, tax_rate_id ← group taxes |
| 2.6 | `warranties` | name, duration (int), duration_unit (days/months/years), description |
| 2.7 | `price_groups` | name, description, is_active ← selling price groups |
| 2.8 | `variation_templates` + `variation_template_values` | template: name / value: template_id, value, sort |
| 2.9 | `products` | **هنفصّله تحت** |
| 2.10 | `product_attributes` | product_id, name ("اللون")، variation_template_id nullable ← بديل `product_variations` القديم |
| 2.11 | `variations` | **هنفصّله تحت** |
| 2.12 | `variation_barcodes` | variation_id, barcode (unique), unit_id nullable ← **جديد**: كذا باركود لنفس الصنف، وباركود للكرتونة |
| 2.13 | `variation_prices` | variation_id, price_group_id, price_type (fixed/percent), value. unique(variation_id, price_group_id) |
| 2.14 | `combo_items` | combo_variation_id, component_variation_id, unit_id, qty |
| 2.15 | `location_product` | location_id, product_id, rack, row, position ← المنتج متاح في الفرع ومكانه على الرف |
| 2.16 | `product_modifier_set` | product_id, modifier_product_id ← الإضافات (للمطاعم) |
| 2.17 | `discounts` | name, type (fixed/percent), value, priority, starts_at, ends_at, location_id nullable, price_group_id nullable, applies_to_customer_groups (bool), is_active |
| 2.18 | `discountables` | discount_id, discountable_type/id (variation/product/brand/category) |
| 2.19 | `label_templates` | name, paper settings json (width/height/rows/cols/margins), is_default ← بديل `barcodes` |

### `products`
```
name, sku (unique), type (single|variable|combo|service|modifier)
unit_id, secondary_unit_id (nullable)
brand_id, category_id (nullable)
tax_rate_id (nullable), tax_type (inclusive|exclusive)
barcode_type (C128|EAN13|...)
track_stock (bool)                ← false = خدمة، مفيش مخزون
alert_qty (decimal nullable)
track_lots (bool), track_expiry (bool), expiry_period (int), expiry_unit (days|months)
track_serials (bool)              ← IMEI / Serial
warranty_id (nullable), weight (decimal nullable)
is_sellable (bool)                ← بديل not_for_selling
is_active (bool), preparation_minutes (nullable, للمطاعم)
description (text), custom_fields json
created_by, softDeletes, timestamps
index(category_id), index(brand_id), fulltext(name, sku) لو MySQL
```

### `variations`
```
product_id (cascade), name ("أحمر / XL" أو "DEFAULT" للـ single)
attribute_values json   ← {"اللون":"أحمر","المقاس":"XL"}
sku (unique)
purchase_price, purchase_price_inc_tax
profit_margin (decimal 7,2)
sell_price, sell_price_inc_tax
is_default (bool), sort
softDeletes, timestamps
index(product_id)
```
> المخزون **مش** متخزن هنا خالص. مكانه في `stock_levels`.

---

## 3. الحسابات (Chart of Accounts): لازم تتعمل قبل الدرج والدفع

| # | الجدول | أهم الأعمدة |
|---|---|---|
| 3.1 | `accounts` | parent_id (self), code (unique), name, type (asset/liability/equity/income/expense), sub_type (cash/bank/receivable/payable/inventory/cogs/sales/tax/...), is_system (bool, ممنوع يتمسح), is_postable (bool, ورقة مش أب), is_active, bank_name, account_number, currency_id nullable |
| 3.2 | `payment_methods` | name, type (cash/card/bank_transfer/cheque/wallet/other), account_id (الحساب اللي الفلوس بتدخله افتراضيًا), is_active, sort ← **بدل `custom_pay_1..7`** |

> بيتعمل Seeder لشجرة حسابات افتراضية: الخزينة، البنك، العملاء، الموردين، المخزون، المبيعات، تكلفة المبيعات، الضرائب، الخصومات، المصروفات، رأس المال.

---

## 4. الدرج والورديات (Cash Management)

| # | الجدول | أهم الأعمدة |
|---|---|---|
| 4.1 | `cash_registers` | location_id, name, cash_account_id (FK accounts), is_active |
| 4.2 | `cash_sessions` | cash_register_id, user_id, status (open/closed), opened_at, opening_float, closed_at, closed_by, expected_cash, counted_cash, difference, card_slips_count, cheques_count, denominations json, closing_note. **index(cash_register_id, status)** |
| 4.3 | `cash_movements` | cash_session_id, direction (in/out), amount, reason, created_by, timestamps ← cash in/out يدوي |
| 4.4 | `cash_denominations` | value (decimal), is_active, sort |

> مبيعات الكاش **مش** بتتسجل هنا. بتيجي من `payments.cash_session_id`، والمتوقع في الدرج = الافتتاحي + مدفوعات الكاش + cash in − cash out − المرتجعات.

---

## 5. العملاء والموردين (CRM)

| # | الجدول | أهم الأعمدة |
|---|---|---|
| 5.1 | `customer_groups` | name, discount_percent, price_group_id nullable |
| 5.2 | `contacts` | **هنفصّله تحت** |
| 5.3 | `contact_user` | contact_id, user_id ← اليوزر يشوف عملاء معينين بس (بديل `user_contact_access`) |
| 5.4 | `contact_opening_balances` | contact_id, amount, direction (receivable/payable), as_of_date, note ← بيعمل قيد، وينفع يتسدد عليه |
| 5.5 | `ledger_discounts` | number, contact_id, date, amount, note, created_by, voided_at ← **خصم على كشف الحساب** (خاص بالنسخة دي) |
| 5.6 | `loyalty_transactions` | contact_id, points (signed int), source_type/id (morph), expires_at, created_at ← بدل `rp_earned/rp_redeemed` المتفرقين |

### `contacts`
```
type (customer|supplier|both), code (unique)   ← contact_id القديم
name, business_name, mobile, alt_mobile, landline, email
tax_number, address, city, state, country, zip_code, shipping_address
customer_group_id (nullable)
credit_limit (decimal nullable), pay_term_number, pay_term_unit (days|months)
is_default (bool)        ← العميل النقدي (Walk-in)
is_active, dob (nullable)
balance (decimal)        ← cache للرصيد (مدين/دائن)، بيتحدث في نفس الـ transaction، وينفع يتعاد بناؤه من القيود
loyalty_points (int)     ← cache
custom_fields json, created_by, softDeletes, timestamps
index(type), index(mobile), index(name)
```

---

## 6. المخزون (Inventory): أهم قسم

| # | الجدول | أهم الأعمدة |
|---|---|---|
| 6.1 | `lots` | variation_id, number, mfg_date, exp_date. unique(variation_id, number) |
| 6.2 | `stock_movements` | **مصدر الحقيقة الوحيد للمخزون**، هنفصّله تحت |
| 6.3 | `stock_levels` | location_id, variation_id **(primary key مركّب)**, qty_on_hand, qty_reserved, avg_cost, updated_at |
| 6.4 | `stock_layers` | طبقات FIFO، هنفصّله تحت |
| 6.5 | `stock_layer_consumptions` | stock_layer_id, consumer_type/id (morph: sale_item, adjustment_item, transfer_item, purchase_return_item), qty, unit_cost, created_at |
| 6.6 | `serials` | variation_id, serial, status (in_stock/sold/returned/damaged), location_id, purchase_item_id, sale_item_id. unique(variation_id, serial) |
| 6.7 | `stock_adjustments` | number, location_id, date, type (opening/increase/decrease/damage/expired), reason, total_cost, recovered_amount, note, created_by, voided_at |
| 6.8 | `stock_adjustment_items` | stock_adjustment_id, variation_id, unit_id, qty (signed), base_qty, unit_cost, lot_id, line_total |
| 6.9 | `stock_transfers` | number, from_location_id, to_location_id, status (draft/in_transit/received/cancelled), sent_at, received_at, shipping_cost, note, created_by |
| 6.10 | `stock_transfer_items` | stock_transfer_id, variation_id, unit_id, qty_sent, qty_received, base_qty, unit_cost, lot_id |
| 6.11 | `stock_counts` | number, location_id, status (draft/counting/posted), counted_at, note ← **جديد: جرد فعلي** |
| 6.12 | `stock_count_items` | stock_count_id, variation_id, lot_id, expected_qty, counted_qty, difference, unit_cost |

> **رصيد أول المدة** هو مجرد `stock_adjustment` نوعه `opening`. مش محتاج جدول لوحده.

### `stock_movements`
```
location_id, variation_id, lot_id (nullable)
qty (decimal 18,4 signed)     ← + وارد / − صادر، بالوحدة الأساسية
unit_cost (decimal 18,4)
type (purchase|purchase_return|sale|sale_return|adjustment|transfer_in|transfer_out|count|opening)
source_type, source_id        ← morph: السطر اللي عمل الحركة
reverses_id (nullable self FK) ← لو الحركة دي عكس لحركة تانية (تعديل أو إلغاء)
moved_at (dateTime), created_by, created_at
index(variation_id, location_id, moved_at)
index(source_type, source_id)
```
**القاعدة:** `stock_levels.qty_on_hand = SUM(stock_movements.qty)`، ودايمًا بيتحدث في نفس الـ DB transaction. وفيه أمر `stock:rebuild` بيعيد بناؤه.

### `stock_layers`
```
location_id, variation_id, lot_id (nullable)
source_type, source_id        ← purchase_item / adjustment_item (opening/increase) / transfer_item (in) / sale_return_item
received_at (dateTime), exp_date (nullable)
original_qty, remaining_qty, unit_cost
index(variation_id, location_id, remaining_qty, received_at)   ← لأسرع FIFO lookup
```
> البيع بيستهلك من أقدم layer لسه فيها رصيد (أو أقرب تاريخ صلاحية)، ويسجّل `stock_layer_consumptions`، فالتكلفة بتطلع مظبوطة على طول. ده بديل `transaction_sell_lines_purchase_lines` و `mapPurchaseSell` بكل مشاكلهم.

---

## 7. المشتريات (Purchasing)

| # | الجدول | أهم الأعمدة |
|---|---|---|
| 7.1 | `purchase_requisitions` | number, location_id, required_by, status, note, created_by |
| 7.2 | `purchase_requisition_items` | requisition_id, variation_id, unit_id, qty |
| 7.3 | `purchase_orders` | number, supplier_id, location_id, order_date, expected_at, status (draft/sent/partial/received/cancelled), currency_id, exchange_rate, subtotal, discount_*, tax_amount, shipping, total |
| 7.4 | `purchase_order_items` | purchase_order_id, variation_id, unit_id, qty, qty_received, unit_cost, tax_rate_id, line_total |
| 7.5 | `purchases` | **هنفصّله تحت** |
| 7.6 | `purchase_items` | **هنفصّله تحت** |
| 7.7 | `purchase_costs` | purchase_id, name, amount, distribute_by (value/qty/weight) ← **Landed cost**: بديل `additional_expense_key_1..4` |
| 7.8 | `purchase_returns` | number, purchase_id (nullable، ولو null يبقى مرتجع مستقل), supplier_id, location_id, date, subtotal, tax_amount, total, paid_total, due_total, payment_status, note, voided_at |
| 7.9 | `purchase_return_items` | purchase_return_id, purchase_item_id (nullable), variation_id, unit_id, qty, base_qty, unit_cost, tax_amount, line_total, lot_id |

### `purchases`
```
number (unique), supplier_ref (رقم فاتورة المورد)
supplier_id, location_id, purchase_order_id (nullable)
purchase_date, due_date (nullable)
status (pending|received)
currency_id, exchange_rate (decimal 18,6)
subtotal, discount_type, discount_value, discount_amount
tax_rate_id, tax_amount, shipping_charges, total
paid_total, due_total, payment_status (due|partial|paid)
note, custom_fields json, created_by, voided_at, void_reason, timestamps
index(supplier_id, purchase_date), index(location_id, purchase_date), index(payment_status)
```

### `purchase_items`
```
purchase_id (cascade), variation_id, purchase_order_item_id (nullable)
unit_id, unit_multiplier, qty, base_qty
unit_cost_before_discount, discount_type, discount_value, unit_cost
tax_rate_id, tax_amount, unit_cost_inc_tax
landed_unit_cost        ← بعد توزيع المصاريف الإضافية، ودي اللي بتدخل الـ stock layer
line_total, qty_returned
lot_number, mfg_date, exp_date   ← بيتعمل منهم lot
new_sell_price (nullable)        ← تعديل سعر البيع من شاشة الشراء
```

---

## 8. المبيعات (Sales)

### 8.1 جداول مساعدة (لازم تتعمل قبل `sales`)
| الجدول | أهم الأعمدة |
|---|---|
| `service_types` | name, price_group_id, packing_charge_type, packing_charge, location_ids json ← Dine-in / Takeaway / Delivery |
| `restaurant_tables` | location_id, name, area, seats, pos_x, pos_y, is_active ← (بيتعمل هنا علشان FK) |

### 8.2 `sales_orders` + `sales_order_items`
```
sales_orders: number, customer_id, location_id, order_date, delivery_date, status (open/partial/invoiced/cancelled), subtotal, discount_*, tax_amount, shipping, total, note, created_by
sales_order_items: sales_order_id, variation_id, unit_id, qty, qty_invoiced, unit_price, discount_*, tax_rate_id, tax_amount, line_total
```

### 8.3 `sales` (فاتورة بيع: POS وكمان بيع مباشر)
```
invoice_no (unique nullable)     ← بيتعمل بس لما الفاتورة تبقى final
draft_no (nullable)              ← رقم المسودة أو عرض السعر
status (draft|quotation|proforma|suspended|final)
channel (pos|direct|import)
location_id, customer_id, cash_session_id (nullable), sales_order_id (nullable)
sale_date (dateTime), due_date (nullable)
price_group_id, service_type_id, table_id, waiter_id, commission_agent_id (nullable)
subtotal
discount_type, discount_value, discount_amount
tax_rate_id, tax_amount
shipping_charges, packing_charge, round_off
total, paid_total, due_total, payment_status (due|partial|paid)
cost_total                        ← COGS، بيتحسب وقت الـ finalize
points_earned, points_redeemed, points_redeem_amount
shipping_status (nullable), shipping_address, delivered_to, delivery_person_id
note, staff_note
public_token (ulid unique)        ← /invoice/{token}
import_batch (nullable)           ← علشان نقدر نلغي استيراد كامل
custom_fields json
created_by, updated_by, voided_at, void_reason, voided_by, timestamps

index(location_id, sale_date)
index(customer_id, sale_date)
index(status, payment_status)
index(cash_session_id)
```

### 8.4 `sale_items`
```
sale_id (cascade), variation_id, product_id (denormalized للتقارير)
parent_item_id (nullable self)    ← modifier أو مكوّن combo
sales_order_item_id (nullable)
unit_id, unit_multiplier, qty, base_qty
unit_price_before_discount, discount_type, discount_value, unit_price
tax_rate_id, tax_amount, unit_price_inc_tax
line_total
unit_cost, cost_total             ← من stock_layer_consumptions
qty_returned
lot_id, warranty_id, service_staff_id (nullable)
kitchen_status (nullable: pending/cooking/ready/served), kitchen_station_id
note
index(sale_id), index(variation_id), index(product_id)
```
- `sale_item_serials`: sale_item_id, serial_id

### 8.5 المرتجعات
```
sale_returns: number, sale_id (nullable), customer_id, location_id, cash_session_id, return_date,
              subtotal, discount_amount, tax_amount, total, refunded_total, due_total, payment_status,
              reason, created_by, voided_at
sale_return_items: sale_return_id, sale_item_id (nullable), variation_id, unit_id, qty, base_qty,
                   unit_price, tax_amount, line_total, restock (bool), unit_cost
```

### 8.6 `recurring_profiles`
```
recurrable_type/id (sale|expense), interval (int), interval_unit (day|week|month|year),
repetitions (nullable), generated_count, next_run_at, is_active
```

---

## 9. المدفوعات والمالية (Finance)

| # | الجدول | أهم الأعمدة |
|---|---|---|
| 9.1 | `payments` | **هنفصّله تحت**. ده جدول واحد لكل فلوس داخلة أو خارجة، **ومنه سندات القبض والصرف** |
| 9.2 | `payment_allocations` | payment_id, allocatable_type/id (sale, purchase, sale_return, purchase_return, expense, contact_opening_balance, ledger_discount), amount ← دفعة واحدة بتتوزع على كذا فاتورة |
| 9.3 | `journal_entries` | number, entry_date, description, source_type/id (morph), location_id, is_manual, reversed_entry_id (nullable), created_by |
| 9.4 | `journal_lines` | journal_entry_id (cascade), account_id, entry_date (denorm), debit, credit, contact_id, location_id, memo. **index(account_id, entry_date)** و **index(contact_id, entry_date)** |
| 9.5 | `account_transfers` | number, from_account_id, to_account_id, amount, date, note ← fund transfer و deposit |
| 9.6 | `expense_categories` | name, code, parent_id, account_id (حساب المصروف) |
| 9.7 | `expenses` | number, location_id, expense_category_id, contact_id (nullable), expense_for_user_id, expense_date, subtotal, tax_rate_id, tax_amount, total, paid_total, due_total, payment_status, is_refund, note, created_by, voided_at |

### `payments`
```
number (unique)                  ← RV-0001 للقبض / PV-0001 للصرف
direction (in|out)
contact_id (nullable)
counter_account_id (nullable)    ← لو السند مش لعميل أو مورد (إيراد أو مصروف متفرق)
location_id, cash_session_id (nullable)
payment_method_id, account_id    ← الخزينة أو البنك
amount, paid_at (dateTime)
reference, cheque_number, cheque_date, bank_name, card_last4
note, is_online (bool), gateway (nullable), gateway_ref (nullable)
created_by, voided_at, void_reason, timestamps
index(contact_id, paid_at), index(account_id, paid_at), index(cash_session_id)
```
**ملاحظات:**
- **الرصيد المقدم:** هو `amount − SUM(allocations)` لنفس الدفعة. بيتعرض كـ "رصيد غير مخصص"، وينفع يتخصص على فاتورة بعدين.
- **سندات القبض والصرف:** هي نفسها `payments`، بس بطباعة رسمية وتفقيط عربي. فمفيش جدول منفصل، والكشف والخزينة بيبقوا متطابقين على طول.
- **القيد الآلي:** كل payment و sale و purchase و expense و adjustment بيعمل `journal_entry` عن طريق `PostingRules`، والقيد لازم يتوازن (مجموع المدين = مجموع الدائن).

---

## 10. المطاعم (Restaurant)

| الجدول | أهم الأعمدة |
|---|---|
| `bookings` | location_id, table_id, contact_id, waiter_id, starts_at, ends_at, status (booked/arrived/completed/cancelled), guests, note |
| `kitchen_stations` | location_id, name, printer_id ← **جديد** |
| `category_kitchen_station` | category_id, kitchen_station_id ← كل كاتيجوري بتتبعت لمحطة معينة |

> حالة الطلب في المطبخ متخزنة على `sale_items.kitchen_status`.

---

## 11. الطباعة والإشعارات

| الجدول | أهم الأعمدة |
|---|---|
| `invoice_layouts` | name, type (a4/thermal_80/thermal_58), settings json (الـ 100+ خيار القديمين بقوا JSON بـ DTO)، is_default |
| `printers` | name, connection (browser/network/qz), ip, port, chars_per_line, location_id |
| `notification_templates` | event (new_sale/payment_received/payment_reminder/...), channel (mail/sms/whatsapp), subject, body, auto_send, is_active. unique(event, channel) |

---

## 12. ترتيب ملفات الـ Migration

```
0001_01_01_*   Laravel defaults (users, cache, jobs)
2026_01_01_0001  permission tables, settings, activity_log, media, notifications
2026_01_01_0010  currencies
2026_01_01_0020  locations, location_user
2026_01_01_0030  document_sequences, custom_field_definitions, notes
2026_01_02_0001  units, brands, categories, tax_rates, tax_rate_group, warranties, price_groups
2026_01_02_0010  variation_templates (+values)
2026_01_02_0020  products, product_attributes, variations, variation_barcodes, variation_prices
2026_01_02_0030  combo_items, location_product, product_modifier_set
2026_01_02_0040  discounts, discountables, label_templates
2026_01_02_0050  add FK locations.default_price_group_id
2026_01_03_0001  accounts, payment_methods
2026_01_03_0010  cash_registers, cash_sessions, cash_movements, cash_denominations
2026_01_04_0001  customer_groups, contacts, contact_user, contact_opening_balances, ledger_discounts, loyalty_transactions
2026_01_05_0001  lots, stock_movements, stock_levels, stock_layers, stock_layer_consumptions, serials
2026_01_05_0010  stock_adjustments (+items), stock_transfers (+items), stock_counts (+items)
2026_01_06_0001  purchase_requisitions, purchase_orders, purchases, purchase_costs, purchase_returns (+items)
2026_01_07_0001  service_types, restaurant_tables
2026_01_07_0010  sales_orders (+items), sales, sale_items, sale_item_serials, sale_returns (+items), recurring_profiles
2026_01_08_0001  payments, payment_allocations
2026_01_08_0010  journal_entries, journal_lines, account_transfers, expense_categories, expenses
2026_01_09_0001  bookings, kitchen_stations, category_kitchen_station
2026_01_09_0010  invoice_layouts, printers, notification_templates (+ FKs على locations)
2026_01_10_0001  legacy_id_map (لنقل الداتا)
```
> الجداول دي **مش هتتعمل كلها مرة واحدة**. كل مرحلة بتعمل الـ migrations بتاعتها بس (Foundation الأول، وبعدها Catalog وهكذا). الخطة دي بتضمن إن الترتيب والعلاقات تبقى سليمة من الأول.

**المجموع:** حوالي 75 جدول (منهم حوالي 15 من الـ packages)، مقابل حوالي 60 في النظام القديم. الجداول زادت، بس كل جدول بقى ليه مسؤولية واحدة واضحة، وأقل في الأعمدة الفاضية.

---

## 13. Mapping من القديم للجديد (علشان نقل الداتا)

| القديم | الجديد |
|---|---|
| `business` + JSON settings | `settings` (spatie) |
| `business_locations` | `locations` |
| `users` + permission `location.{id}` | `users` + `location_user` |
| `products` + `product_variations` + `variations` | `products` + `product_attributes` + `variations` |
| `variation_group_prices` | `variation_prices` |
| `variation_location_details` | `stock_levels` (بيتعاد بناؤه من الحركات) |
| `product_locations` + `product_racks` | `location_product` |
| `contacts` | `contacts` (+ `contact_opening_balances` من `transactions` type=opening_balance) |
| `transactions` type=sell / sales_order / sell_return | `sales` / `sales_orders` / `sale_returns` |
| `transaction_sell_lines` | `sale_items` |
| `transactions` type=purchase / purchase_order / purchase_return / purchase_requisition | `purchases` / `purchase_orders` / `purchase_returns` / `purchase_requisitions` |
| `purchase_lines` | `purchase_items` + `stock_layers` |
| `transactions` type=opening_stock / stock_adjustment | `stock_adjustments` (type=opening / increase / decrease) |
| `transactions` type=sell_transfer + purchase_transfer | `stock_transfers` (مستند واحد) |
| `transaction_sell_lines_purchase_lines` | `stock_layer_consumptions` |
| `transactions` type=expense | `expenses` |
| `transactions` type=ledger_discount | `ledger_discounts` |
| `transaction_payments` (+ parent_id) | `payments` + `payment_allocations` |
| `transaction_payments` type=custom_receipt / custom_payment | `payments` (سندات القبض والصرف) |
| `accounts` + `account_transactions` | `accounts` (شجرة) + `journal_entries/lines` |
| `cash_registers` + `cash_register_transactions` | `cash_registers` + `cash_sessions` + `cash_movements` |
| `types_of_services` | `service_types` |
| `res_tables` | `restaurant_tables` |
| `barcodes` | `label_templates` |

### `legacy_id_map`
```
legacy_table, legacy_id, new_type, new_id, migrated_at
unique(legacy_table, legacy_id)
```
> أمر `migrate:legacy` بيبقى **idempotent**، يعني ينفع يتشغّل أكتر من مرة من غير ما يكرر الداتا، ومعاه `--dry-run`. وفي الآخر بيتشغّل reconciliation يقارن المبيعات والمديونيات والمخزون لكل فرع وكل شهر بين القديم والجديد.

---

## 14. قرارات محتاجة تأكيد

1. **DB engine:** MySQL 8 (مقترح) ولا PostgreSQL؟
2. **التكلفة:** FIFO بس، ولا كمان Weighted Average كاختيار في الإعدادات؟ (الـ schema فوق بيدعم الاتنين: `stock_layers` للـ FIFO و `stock_levels.avg_cost` للمتوسط)
3. **نقل الداتا:** التاريخ كله، ولا أرصدة افتتاحية في تاريخ قطع معيّن؟ (الأرصدة الافتتاحية أسرع وأنضف بكتير، والقديم يفضل متاح read-only للرجوع ليه)
4. **عرض السعر والمسودة:** يفضلوا جوه `sales` بالـ status (المقترح هنا)، ولا جدول منفصل؟

---

## 15. حالة التنفيذ (2026-11-17)

- ✅ اتعمل مشروع **Laravel 13.42** على **PHP 8.4**، واتسطّب **Filament 5.11** و spatie (permission و settings و activitylog و medialibrary) و brick/money و Pest و Larastan.
- ✅ كل الـ migrations اتكتبت في `database/migrations/2026_01_0*` (حوالي 85 جدول للـ domain، و104 جدول بالـ packages). اتجرّبت دورة كاملة: `migrate:fresh` وبعده `rollback` وبعده `migrate`، من غير ولا خطأ.
- ✅ أسماء الـ indexes والـ FKs كلها أقل من 64 حرف، يعني متوافقة مع MySQL.
- ⚠️ التطوير حاليًا على **SQLite**، لأن MySQL مش متسطب على الجهاز. الـ fulltext index على المنتجات بيتعمل بس لما الـ driver يكون MySQL.
- ✅ اتعملت مراجعة عمود بعمود ضد الـ 300 migration القديمة، والحاجات اللي طلعت ناقصة اتضافت: `sales.delivery_date`، و`sales.customer_group_id`، و`recurring_profiles.repeat_on/stopped_at`، و`payments.card_type`، و`tax_rates.for_group_only`، و`discounts.applies_with_price_group`، و`location_service_type`، و`location_payment_method`، و`product_unit`، و`*.secondary_qty`.

## 16. إعدادات business القديمة: هتتحوّل لـ Settings classes (spatie)

| Class | المفاتيح (من جدول `business` القديم) |
|---|---|
| `GeneralSettings` | business_name, logo (media), currency_id, currency_precision, quantity_precision, currency_symbol_placement, time_zone, date_format, time_format, fy_start_month, start_date, theme_color, tax_label_1/2, tax_number_1/2, code_label_1/2 + code_1/2 |
| `InventorySettings` | accounting_method (fifo/lifo/avg), enable_brand/category/racks/lots/expiry/sub_units/secondary_unit, expiry_type, on_expiry (stop selling before X days), stock_expiry_alert_days, allow_overselling, sku_prefix, default_unit_id |
| `SaleSettings` | default_sales_discount, default_sales_tax_id, sell_price_tax (inclusive/exclusive), enable_inline_tax, commission_agent_mode (disabled/logged_in_user/user/cmsn_agnt), commission_calculation, item_addition_method, amount_rounding_method, default_credit_limit, is_pay_term_required |
| `PurchaseSettings` | enable_purchase_status, enable_editing_product_from_purchase, purchase_in_diff_currency, purchase_currency_id, p_exchange_rate, default_profit_percent, enable_purchase_order, enable_purchase_requisition |
| `PosSettings` | disable_* buttons (draft/express/card/multiple_pay/suspend/credit_sale/discount/order_tax), hide_product_suggestion, hide_recent_trans, show_featured_products, enable_service_staff, is_service_staff_required, enable_weighing_scale, cash_denominations, require_open_register, keyboard_shortcuts, customer_display |
| `WeighingScaleSettings` | label_prefix, product_sku_length, qty_length, qty_length_decimal |
| `LoyaltySettings` | enable_rp, rp_name, amount_for_unit_rp, min_order_total_for_rp, max_rp_per_order, redeem_amount_per_unit_rp, min_order_total_for_redeem, min_redeem_point, max_redeem_point, rp_expiry_period, rp_expiry_type |
| `NotificationSettings` | email (mailer/host/port/user/pass/from)، sms (gateway + params)، whatsapp (Cloud API token/phone id)، auto payment reminders |
| `LabelSettings` | custom_labels (payments/contacts/products/locations/users/purchases/sells/shipping/types_of_service) |

> ترقيم المستندات (`ref_no_prefixes` و `invoice_schemes`) مكانه `document_sequences`، مش الإعدادات.

## 17. حاجات سبناها بقصد (مش منسية)

| القديم | القرار |
|---|---|
| حقول HR في `users` (gender، blood_group، guardian، id_proof، bank_details، العناوين، social links) | هتدخل مع موديول HRM بعدين، أو `custom_fields` لحد ما يوصل |
| `users.crm_contact_id` (دخول العميل على البوابة) | هيتضاف مع الـ Customer portal |
| `users.available_at/paused_at` (توفّر service staff) | هيتضاف مع الـ Restaurant phase لو العميل محتاجه |
| `contacts.prefix/first/middle/last_name` | هيتدمجوا في `name` وقت نقل الداتا |
| عناوين ZATCA (`building_number`, `street_name`, `additional_number`) و `export_custom_field_*` و `shipping_custom_field_*` و `service_custom_field_*` | هيتحطوا في `custom_fields` (مفيش فاتورة إلكترونية) |
| `transactions.prefer_payment_method/account` | مش هنحتاجه، لأن طرق الدفع بقت لكل فرع |
| `purchase_lines.mfg_quantity_used`، و `production_*`، و `hms_*`، و `gym_*`، و `repair_*` | هيدخلوا مع موديولاتهم (Manufacturing وغيرها) |
| `is_created_from_api` و `oauth_*` | الـ API هيبقى Sanctum |
| `dashboard_configurations` | Filament widgets |
| ZATCA/QR في invoice layouts | مش في الـ scope (القرار: مفيش فاتورة إلكترونية) |

## 18. المرحلة الأولى: الأساس (اتعمل في 2026-11-17)

| الجزء | المكان |
|---|---|
| 38 Enum بترجمة عربي وإنجليزي، و Filament بيعرضها بالـ label واللون | `app/Domain/*/Enums` + `lang/{ar,en}/enums.php` |
| 75 Model فيهم relations بالـ generics، و casts، والقيم الافتراضية (`$attributes`) | `app/Domain/*/Models` |
| Morph map إجباري، يعني بيتخزن `sale_item` مثلًا مش اسم الكلاس | `app/Support/MorphMap.php` |
| 9 Settings classes بقيم افتراضية | `app/Settings` + `database/settings` |
| ترقيم المستندات (فرع، سنة، عام) بـ lock | `app/Domain/Foundation/Services/DocumentNumberGenerator.php` |
| 116 صلاحية و5 أدوار (مدير النظام، مدير، محاسب، أمين مخزن، كاشير) | `config/permissions.php` + `lang/*/permissions.php` |
| لوحة admin على `/admin`، ولوحة pos على `/pos`، بالعربي و RTL وخط Cairo | `app/Providers/Filament` |
| 13 Seeder للأساس (كلهم idempotent) + DemoSeeder | `database/seeders` |
| 241 test (Pest)، و Larastan level 5 من غير أخطاء، و Pint | `tests/`، `composer check` |

**الأوامر:**
- `composer fresh`: قاعدة بيانات نظيفة ومعاها البيانات الأساسية.
- `composer fresh-demo`: نفس اللي فوق، ومعاه بيانات تجريبية.
- `composer check`: Pint ثم Larastan ثم الـ tests.

**حسابات الدخول التجريبية:** كلهم بكلمة سر `password`.
- `admin@newpos.test`
- `cashier@newpos.test`
- `accountant@newpos.test`
- `store@newpos.test`

> الـ Models اتعملت بـ generator مرة واحدة بس. من دلوقتي بتتعدّل بإيدينا، فأي عمود جديد في migration لازم يتضاف للـ model كمان (`fillable` و `casts` و `$attributes`). الـ test اللي اسمه `ModelIntegrityTest` هيمسك أي حاجة ناقصة.

## 19. المرحلة التانية: الكتالوج والمخزون (اتعمل في 2026-11-17)

| الجزء | المكان |
|---|---|
| **Stock Ledger**: وارد وصادر وعكس، بـ FIFO أو LIFO أو المتوسط المرجح، و FEFO للصلاحية، ومنع بيع التشغيلات القريبة من الانتهاء، والبيع على المكشوف (والوارد بعده بيغطّي العجز) | `app/Domain/Inventory/Services/StockLedger.php` |
| Actions: تسوية (رصيد أول، زيادة، نقص، تالف، منتهي)، وإلغاء تسوية، وتحويل (إنشاء، إرسال، استلام، إلغاء) | `app/Domain/Inventory/Actions` |
| `SaveProduct`: كل أنواع المنتجات، والأسعار شاملة الضريبة وغير شاملة، وهامش الربح، والـ SKU التلقائي، ومنع تكرار الباركود، وأسعار مجموعات الأسعار، والكومبو | `app/Domain/Catalog/Actions/SaveProduct.php` |
| تحويل الوحدات (الكرتونة = 12 قطعة) | `app/Domain/Catalog/Services/UnitConverter.php` |
| `php artisan stock:rebuild [--dry-run]` | بيقارن cache الأرصدة بالحركات ويصلّحه |
| شاشات Filament: الوحدات، والماركات، والتصنيفات، والضرائب (والمجموعات الضريبية)، والضمانات، ومجموعات الأسعار، وقوالب الأصناف، والمنتجات (ومعاها كارت الصنف)، والتسويات، والتحويلات، وأرصدة المخزون وقيمتها، والفروع، والمستخدمين | `app/Filament/Admin/Resources` |
| الصلاحيات على كل شاشة، وتقييد الموظف بفروعه، والمستندات متتعدلش (بتتلغي بس) | `AuthorizesWithPermissions` و `ScopesToUserLocations` |
| ترجمة عربي لكل نصوص الواجهة، ومعاها test بيتأكد إن مفيش نص من غير ترجمة | `lang/ar.json` |
| 292 test، و Larastan level 5 من غير أخطاء (`parseModelCastsMethod: true`) | |

**مؤجّل للمراحل الجاية:** قيود اليومية لحركات المخزون (مع مرحلة المالية)، والجرد الفعلي (`stock_counts`، الجدول جاهز)، والاستلام الجزئي في التحويلات، وطباعة الباركود، واستيراد المنتجات من Excel، وشاشة الخصومات المجدولة.

## 20. المرحلة التالتة: العملاء والموردين والمشتريات والمدفوعات (اتعمل في 2026-11-17)

| الجزء | المكان |
|---|---|
| `DocumentCalculator`: خصم السطر وضريبته، وخصم الفاتورة، وضريبة الفاتورة على المبلغ بعد الخصم، والشحن والمصاريف. التقريب لدقة العملة. هيتستخدم في المبيعات كمان | `app/Domain/Shared/Services` |
| `PaymentStatusRefresher`: حالة الدفع (مستحق، جزئي، مدفوع) بتتحسب من التخصيصات، ومن غير الدفعات الملغاة | |
| `ContactLedger`: كشف الحساب `UNION ALL` على كل المستندات، ومعاه الرصيد المرحّل والرصيد الجاري. و `contacts.balance` بيتحسب من نفس الـ query | `app/Domain/CRM/Services` |
| المدفوعات: `RecordPayment` (سند قبض RV أو سند صرف PV)، و `AllocatePayment` (توزيع على أقدم المستحقات، والباقي رصيد مقدم بيتخصص بعدين)، و `VoidPayment`. ومفيش تخصيص إلا لمستند في نفس الاتجاه ولنفس جهة الاتصال | `app/Domain/Finance` |
| المشتريات: فاتورة بتكلفة واصلة (الخصم والشحن والمصاريف بتتوزع حسب القيمة أو الكمية أو الوزن)، وعملة أجنبية، واستلام مؤجل، وتحديث سعر البيع، وتحديث تقدّم أمر الشراء، وإلغاء محمي (مرفوض لو عليها مدفوعات أو مرتجعات أو جزء منها اتباع) | `app/Domain/Purchasing` |
| مرتجع الشراء: من فاتورة (بحد أقصى المتاح للإرجاع، وبيخرج من طبقة الفاتورة الأول) أو مستقل | |
| جهات الاتصال: كود تلقائي، ورصيد افتتاحي ينفع يتسدد عليه، وخصم على الحساب باتجاه | |
| الشاشات: العملاء والموردين (ومعاهم كشف حساب ينفع يتطبع، وتحصيل وسداد، و"عملاؤه فقط")، والمشتريات، وأوامر الشراء، ومرتجعات الشراء، والمدفوعات | `app/Filament/Admin/Resources` |
| 314 test | |

**تغيير في الـ schema:** `ledger_discounts.direction`. اتعدّل في الـ migration الأصلي، لأن مفيش نسخة في production لسه.
**مؤجّل:** طلبات الشراء (`purchase_requisitions`، الجدول جاهز)، وتعديل فاتورة شراء مستلمة (دلوقتي بتتلغي وتتعمل من جديد)، ومقاصة مرتجع الشراء مع فاتورة لسه مستحقة (الرصيد صح، لكن حالة دفع الفاتورتين منفصلة)، والسندات اللي مش لجهة اتصال زي الإيرادات والمصروفات المتفرقة (هتيجي مع مرحلة المالية)، وطباعة السندات بالتفقيط.

## 21. المرحلة الرابعة: نقطة البيع والمبيعات والدرج (اتعمل في 2026-11-17)

| الجزء | المكان |
|---|---|
| الورديات: فتح (وردية واحدة لكل درج ولكل يوزر)، وإيداع وسحب (مرفوض لو أكبر من الكاش اللي في الدرج)، وقفل (المعدود قدام المتوقع، ومرفوض لو فيه فواتير معلقة)، وتقرير X/Z | `app/Domain/CashManagement` |
| `PriceResolver`: السعر المعروض حسب مجموعة الأسعار (ثابت أو نسبة)، والوحدة، وشامل الضريبة أو لأ | `app/Domain/Catalog/Services` |
| `SaveSale`: مسودة، وعرض سعر، ومعلقة (من غير مخزون ولا فلوس، وينفع تتعدّل)، ونهائية (رقم فاتورة، وخروج مخزون بتكلفته، والكومبو من مكوناته، والدفع، والباقي كاش بس). وبيرفض: الآجل للعميل النقدي، والآجل من غير صلاحية، وتجاوز الحد الائتماني، وتجاوز خصم الكاشير | `app/Domain/Sales/Actions` |
| `VoidSale` (بيرجّع المخزون ويلغي مدفوعات الفاتورة)، و `CreateSaleReturn` (بسعر البيع بعد نصيب السطر من خصم الفاتورة، والرجوع للمخزون اختياري، والرد الفوري اختياري)، و `VoidSaleReturn`، و `DeleteSaleDraft` | |
| **شاشة POS** `/pos/sell`: الكتالوج متحمّل في المتصفح، والبحث فوري، والباركود، وباركود الميزان، والسلة بـ Alpine، ومجموعات الأسعار، وعميل سريع، والدفع (كاش أو كارت أو متعدد أو آجل)، والتعليق والمسودة وعرض السعر والاسترجاع، وآخر الفواتير، والإيداع والسحب، وقفل الوردية، واختصارات الكيبورد. والصلاحيات بتتطبق على السيرفر | `app/Filament/Pos/Pages/PointOfSale.php` + `resources/views/filament/pos` |
| إيصال حراري 80 مم `/pos/receipt/{sale}` (بيطبع لوحده) | `SaleReceiptController` |
| شاشات الإدارة: فواتير البيع، ومرتجعات البيع، والورديات (تقرير، ومدير يقفل وردية غيره)، والأدراج | `app/Filament/Admin/Resources` |
| 340 test، وكمان فحص لمنطق السلة في JavaScript (متعمل يدوي بـ Node، ومش جوه الـ suite) | |

**ملاحظات:**
- أي نص في الواجهة اسمه زي ملف ترجمة (`__('Cash')` قدام `cash.php`) بيرجّع array على الماك. فيه test بيمنع ده.
- `/pos` بيتحوّل لـ `/pos/sell`. والزائر بيتحوّل لصفحة الدخول المناسبة (`redirectGuestsTo`).

**مؤجّل:** نقاط الولاء، والخصومات المجدولة في الـ POS، والـ Modifiers (المطاعم)، والـ Offline/PWA، والطباعة المباشرة ESC/POS (QZ Tray)، وشاشة العميل الثانية، وفاتورة A4 والرابط العام `/invoice/{token}`، والأرقام التسلسلية في البيع، والبيع المباشر من لوحة الإدارة (من غير POS)، وأوامر البيع.

## 22. المرحلة الخامسة: المالية والقيود (اتعمل في 2026-11-17)

| الجزء | المكان |
|---|---|
| **`Posting::sync($document)`**: بيحسب القيد الصح للمستند من حالته الحالية. لو القيد المترحّل مختلف، بيعكسه ويرحّل الجديد. القيود متتعدلش ولا بتتمسح أبدًا، بتتعكس بس | `app/Domain/Finance/Services/Posting.php` |
| **`PostingRules`**: قواعد الترحيل لكل المستندات: البيع (والتكلفة)، والمرتجعات، والشراء (بضاعة في الطريق لحد الاستلام)، والسندات، والمصروفات، وتسويات المخزون (والافتتاحي على حساب الأرصدة الافتتاحية)، والأرصدة الافتتاحية، والخصم على الحساب، والتحويلات، وعجز وزيادة الوردية | `PostingRules.php` |
| `GeneralLedger`: رصيد الحساب (ومعاه فروعه)، ودفتر الأستاذ، وميزان المراجعة بالشجرة | `GeneralLedger.php` |
| Actions: `CreateExpense` (والدفع الفوري أو بعدين)، و `VoidExpense`، و `TransferBetweenAccounts`، و `RecordJournalEntry` (يدوي وعكسه)، و `SaveAccount` | `app/Domain/Finance/Actions` |
| التفقيط العربي (12 عملة) وطباعة سند القبض والصرف A5 | `app/Support/ArabicAmountInWords.php` + `/admin/vouchers/{payment}/print` |
| `php artisan ledger:rebuild`: بيعيد ترحيل كل القيود الآلية بترتيب التاريخ، والقيود اليدوية بتفضل زي ما هي | `app/Console/Commands/RebuildLedger.php` |
| الشاشات: شجرة الحسابات (بالأرصدة، ودفتر الأستاذ لكل حساب)، وقيود اليومية (عرض، وقيد يدوي، وعكس)، والمصروفات وبنودها، والتحويلات بين الحسابات، وسند قبض وصرف عام (من غير عميل أو مورد)، وميزان المراجعة | `app/Filament/Admin` |

**قواعد ثابتة (بتتراجع في كل test):** كل test في `Feature` بيتأكد في الآخر إن:
- كل قيد متوازن.
- حساب المخزون = قيمة حركات المخزون.
- العملاء − الموردين = مجموع أرصدة جهات الاتصال.
- بضاعة في الطريق = المشتريات اللي لسه متستلمتش.
- المصروفات المستحقة = المصروفات اللي لسه متدفعتش.

ده متعمل في `assertLedgerConsistent` جوه `tests/Pest.php`. اختبارات محرك المخزون اللي من غير مستندات بس هي اللي مستثناة.

**قرارات:**
- العملاء والموردين **حسابات مراقبة**، يعني بيتحركوا من المستندات بس. وكمان المخزون، وبضاعة في الطريق، والمصروفات المستحقة. فمينفعش يتعمل عليهم قيد يدوي، وده علشان يفضلوا دايمًا مطابقين للكشوفات وتقييم المخزون.
- اتضاف حسابين نظام: `1109` بضاعة في الطريق، و `2105` مصروفات مستحقة. ومعاهم `AccountSubType::GoodsInTransit` و `AccruedExpenses`.
- المصروف بيترحّل على حساب بند المصروف (ولو مفيش، على مصروفات متنوعة)، والطرف التاني مصروفات مستحقة. والسند اللي بيدفعه ملوش جهة اتصال.
- إيداع وسحب الدرج ورصيد أول الوردية **مش بيترحّلوا**، لأنهم حركة جوه نفس الخزينة. اللي بيترحّل بس هو فرق القفل (عجز أو زيادة).
- المشتريات بالعملة الأجنبية بتترحّل بالعملة المحلية (`total × exchange_rate`)، وكشف حساب المورد بقى كده هو كمان.

**باجات قديمة اتصلحت:**
- `ContactLedger::refresh` كان ممكن ميحفظش الرصيد لو نسخة الموديل اللي في الذاكرة فيها نفس القيمة. ده كان بيحصل في إلغاء الفاتورة من الشاشة، ورصيد العميل النقدي كان بيفضل غلط. اكتشفه فحص القيود.
- كشف حساب المورد كان بيجمع فواتير بعملة أجنبية مع دفعات بالعملة المحلية.

**مؤجّل:**
- تخصيص الدفع على فاتورة شراء بعملة أجنبية: الحالة بتتحسب بعملة الفاتورة، بينما الدفعة بالعملة المحلية.
- قيد `recovered_amount` في تسوية التالف.
- المصروفات المتكررة (`recurring_profiles` جاهز).
- إقفال السنة المالية.
- مراكز التكلفة.
- قائمة الدخل والميزانية: هييجوا مع مرحلة التقارير، ومبنيين على `GeneralLedger`.

## 23. المرحلة السادسة: التقارير ولوحة التحكم (اتعمل في 2026-11-17)

| الجزء | المكان |
|---|---|
| `SalesReport`: المبيعات والمرتجعات ومجمل الربح، مجمّعة حسب اليوم أو الشهر أو الصنف أو التصنيف أو الماركة أو العميل أو الكاشير أو الفرع. المبالغ من غير ضريبة ولا شحن، وكل سطر شايل نصيبه من خصم الفاتورة. يعني نفس أساس القيود، والأرقام بتطابق قائمة الدخل | `app/Domain/Reporting/Services/SalesReport.php` |
| `FinancialStatements`: قائمة الدخل (صافي المبيعات، والتكلفة، ومجمل الربح، والإيرادات الأخرى، والمصروفات، وصافي الربح) والميزانية في تاريخ معيّن. الربح اللي لسه متقفلش بيظهر في حقوق الملكية، ومعاه فحص التوازن | `FinancialStatements.php` |
| `OperationsReports`: المشتريات (حسب المورد أو الصنف أو الشهر أو الفرع)، وتقييم المخزون بالتكلفة وبسعر البيع ومعاه النواقص، والضرائب من حسابات الضريبة في القيود، وأعمار الديون (0–30 و31–60 و61–90 و+90)، والمصروفات، وحركة الخزينة والبنوك (حسب الحساب وحسب المصدر) | `OperationsReports.php` |
| `ReportPage`: كل تقرير ليه فلتر بالفترة والفرع، وكروت ملخص، وجداول، وطباعة، وتصدير CSV يفتح على Excel بالعربي. والموظف بيشوف فروعه بس | `app/Filament/Admin/Pages/Reports` |
| لوحة التحكم: كروت (مبيعات اليوم مقارنة بإمبارح، ومبيعات الشهر، ومجمل الربح، والمصروفات، والمستحق على العملاء وللموردين، والخزينة والبنوك)، ورسم بياني لآخر 30 يوم، والأكثر مبيعًا، والنواقص، وأكبر العملاء مديونية. كل widget بيظهر حسب صلاحية المستخدم | `app/Filament/Admin/Widgets` |
| `SqlDates`: تجميع باليوم والشهر شغال على SQLite و MySQL | |
| 366 test | `tests/Feature/Reporting` |

**ملاحظة:** عمود `date` في Eloquent بيتخزن `Y-m-d H:i:s`، فالفلترة بـ `whereBetween` على `entry_date` لازم تكون بـ datetime، أو تستخدم `whereDate`.

**مؤجّل:**
- قائمة الدخل حسب الفرع (محتاجة فلترة القيود بـ `location_id`).
- المقارنة بين فترتين.
- تقرير أداء الموظفين بالعمولات.
- حركة الصنف (كارت الصنف موجود في شاشة المنتج).
- تصدير PDF (الطباعة من المتصفح شغالة).
- جدولة إرسال التقارير بالإيميل.

## 24. المرحلة السابعة: الإعدادات والطباعة (اتعمل في 2026-11-17)

| الجزء | المكان |
|---|---|
| **إعدادات النشاط**: شاشة واحدة فيها تابات (النشاط واللوجو والأرقام الضريبية، والعملة والتاريخ والمنطقة الزمنية، ونقطة البيع واختصاراتها، والمخزون، والمشتريات، والميزان). بتظهر **الإعدادات اللي البرنامج بيقراها فعلًا بس**، والباقي هيظهر مع الميزة بتاعته | `app/Filament/Admin/Pages/Settings/BusinessSettings.php` |
| المنطقة الزمنية وشكل التاريخ بيتطبقوا على البرنامج كله (`AppServiceProvider::applyBusinessSettings`) | |
| ترقيم المستندات (البادئة، والرقم التالي، وعدد الأرقام، ومعاينة)، وينفع يبقى لكل فرع أو لكل سنة | `Settings/DocumentSequenceResource` |
| طرق الدفع والحساب المرتبط بكل طريقة (طريقة "الرصيد المقدم" الداخلية مش ظاهرة) | `Settings/PaymentMethodResource` |
| الأدوار والصلاحيات: أدوار مخصصة، وصلاحيات مجمّعة زي `config/permissions.php`. مدير النظام مش بيتعدّل، والأدوار الأساسية مش بتتمسح | `Settings/RoleResource` |
| تصميمات الفواتير: الورق (A4 أو A5 أو 80 مم أو 58 مم)، و11 اختيار للي يظهر في الطباعة، ونص الرأس والتذييل، وتغيير أي عنوان، ومعاينة على آخر فاتورة | `Printing/InvoiceLayoutResource` + `PrintLayout` |
| طباعة فاتورة البيع وعرض السعر والمرتجع بـ A4 و A5 والحراري، من بيانات موحّدة (`PrintableDocument`)، ومعاها اللوجو والتفقيط والرصيد السابق وباركود رقم الفاتورة | `DocumentPrintController` + `resources/views/print` |
| باركود **Code 128** بصيغة SVG من غير package، والـ tests بتراجع الـ checksum | `app/Domain/Printing/Services/Code128.php` |
| ورق الملصقات (رول متصل أو ورقة A4 بأعمدة) وصفحة **طباعة الباركود**: تقدر تختار الأصناف بإيدك، أو من المنتجات المحددة، أو من فاتورة شراء (ملصق لكل قطعة) | `Printing/LabelTemplateResource` + `PrintLabels` + `/print/labels` |
| اللوجو بيتطبع data URI، فمش محتاج `storage:link`، وبيظهر على الفاتورة والإيصال والسندات | `GeneralSettings::logoDataUri()` |
| 374 test | `tests/Feature/Settings` |

**تغييرات:**
- `SaleReceiptController` و `pos/receipt.blade.php` اتشالوا، و `/pos/receipt/{sale}` بقى بيستخدم `DocumentPrintController::receipt`.
- قوالب الملصقات اتوحّدت على مفاتيح `LabelSheet::PAPER`.
- اتضاف `general.logo_path` بـ settings migration.

**مؤجّل:**
- الطباعة المباشرة ESC/POS أو QZ Tray من غير نافذة الطباعة (جدول `printers` جاهز).
- QR الفاتورة والرابط العام `/invoice/{token}`.
- قوالب الإشعارات (واتساب، SMS، إيميل) والإرسال.
- النسخ الاحتياطي من الواجهة.
- سجل النشاط (`activity_log.view`).
- الإعدادات اللي لسه ملهاش ميزة: العمولات، والولاء، وأوامر البيع، والشحن.

## 25. الموظفين والسلف والمرتبات (اتعمل في 2026-11-18)

تاب جديدة في القائمة اسمها **"الموظفين والمرتبات"** (`NavigationGroup::Payroll`)، وفيها: الموظفين (اتنقلوا من "العملاء والموردين")، والسلف، ومسيرات الرواتب. الموظف لسه `Contact` من نوع `employee`، وكل الحركة بتعدّي على حساب `EmployeeAccounts` باسمه. عشان كده المرتبات القديمة اللي جاية من Afaky (قيود يدوية) والجديدة بتظهر في نفس كشف الحساب.

| الجزء | المكان |
|---|---|
| جداول `employee_profiles` و `employee_advances` و `employee_advance_settlements` و `payroll_runs` و `payroll_lines` | `2026_01_10_000010_create_payroll_tables.php` |
| بيانات المرتب في فورم الموظف (section مربوط بـ `employeeProfile`): الوظيفة، والفرع، والحالة، والمرتب، والبدلات الثابتة، وتاريخ التعيين، وتاريخ ترك العمل | `EmployeeResource::extraFormComponents()` (hooks جديدة في `ContactResource`) |
| **السلفة** = سند صرف للموظف (مدين حسابه) + صف بيتابع القسط الشهري والمتبقي. السداد بيبقى إما خصم من المسير أو نقدي (سند قبض) | `GiveAdvance` و `RepayAdvance` و `VoidAdvance` و `AdvanceBalances` |
| **المسير**: بيتعمل مسودة بكل الموظفين الشغالين في الفرع، ومعاها المرتب والبدلات وقسط السلف ← تعديل السطور (إضافي، ومكافأة، وأيام غياب بتتحسب على 30 يوم، وجزاءات) ← اعتماد ← صرف للكل أو لموظفين معينين | `GeneratePayroll` و `UpdatePayrollLines` و `ApprovePayroll` و `PayPayroll` و `ReopenPayroll` |
| **القيد** عند الاعتماد: مدين "الرواتب والأجور" (`AccountSubType::Salaries`، الحساب 5202) بالمستحق، ودائن حساب كل موظف بالمستحق بتاعه. الصرف بيعمل سند صرف لكل موظف بالصافي، فالسلفة بتتقفل لوحدها. لو مفيش حساب رواتب بيتسجل على المصروفات المتنوعة | `PostingRules::payroll()` |
| كشف حساب الموظف بيظهر فيه المسير كبند دائن (`ContactLedger`) ومعاه لينك للمسير | |
| **الحماية:** سند السلفة وسند السداد مش بيتلغوا من شاشة السندات، لازم من شاشة السلفة. وإلغاء سند صرف مرتب بيرجّع السطر "متصرفش". والمسير مش بيتفتح ولا بيتلغي طول ما فيه مرتبات اتصرفت. وممنوع يتعمل مسيرين لنفس الشهر لنفس الموظف | `VoidPayment` |
| طباعة كشف الرواتب (A4 بالعرض فيه التوقيعات) وقسائم المرتبات (2 في الصفحة ومعاها التفقيط) | `PayrollPrintController` + `resources/views/payroll` |
| 11 صلاحية جديدة في مجموعة `payroll` (`employees.*` اتنقلت لها) | `config/permissions.php` |
| `ledger:rebuild` بيعيد قيود المسيرات | |
| 424 test | `tests/Feature/Payroll` |

**تاريخ القيد:** آخر يوم في الشهر، ولو الشهر لسه مخلصش بيبقى النهارده، عشان المرتب ميتسجلش بتاريخ بعد صرفه. وتقدر تغيّره من المسير.

**على أي install موجود:** `php artisan migrate` وبعدها `db:seed --class=PermissionSeeder` و `db:seed --class=ChartOfAccountsSeeder` (بيربط الحساب 5202 بدور الرواتب).

**مؤجّل:**
- تقرير المرتبات المجمّع (حسب الشهر أو الفرع أو الموظف). لحد ما يتعمل، استخدم فلاتر ومجاميع شاشة المسيرات وشاشة السلف.
- الحضور والانصراف.
- التأمينات وضريبة كسب العمل: لحد ما يتعملوا، استخدم خانة "خصومات أخرى".
- توزيع مصروف المرتبات على الفروع في مسير "كل الفروع": القيد بيتسجل من غير فرع.
- العمولات.

## 26. المحافظ الإلكترونية وتطبيق الموبايل (اتعمل في 2026-11-18)

تطبيق Flutter (`mobile/`) بيتسطب على موبايلات المحل، ويقرا رسايل فودافون كاش واتصالات كاش وأورنج كاش وInstaPay، ويبعتها للسيستم. **مفيش أي سند بيتسجل غير لما موظف يأكد** التحويل ده كان لإيه. التأكيد ممكن يتعمل من السيستم، أو من الموبايل بالرقم السري بتاع الموظف.

| الجزء | المكان |
|---|---|
| جداول `wallets` و `wallet_message_templates` و `wallet_devices` و `wallet_transactions`، ومعاهم Sanctum `personal_access_tokens` | `2026_01_11_000010_create_wallet_tables.php` |
| **الصيغ بتتعدّل من لوحة التحكم من غير regex:** نص الرسالة، وكلمات زي `{amount}` `{fee}` `{balance}` `{phone}` `{name}` `{wallet}` `{datetime}` `{reference}`، و`{*}` لأي كلام بيتغير. الأرقام العربي (٠-٩ و٫) بتتفهم. شاشة الصيغ فيها "جرّب برسالة حقيقية" | `TemplateCompiler` + `WalletMessageTemplateResource` |
| صيغتين فودافون كاش (وارد وصادر) جاهزين من رسايل حقيقية | `WalletTemplateSeeder` |
| الاستلام: الرسالة الخام بتتحفظ دايمًا، ومفيش تكرار بالـ hash (نفس الـ SMS من موبايلين). ونفس رقم العملية لو اتكرر بيتعلّم "مكررة". الرسالة اللي مش مفهومة بتفضل "مش مفهومة"، وبتتقري تاني لما صيغة تتضاف | `IngestWalletMessage` و `ReadWalletMessageAgain` |
| **التأكيد:** فاتورة بيع (سند قبض متخصص على الفاتورة)، أو جهة اتصال (قبض أو صرف بيسدد أقدم المستحق)، أو بند مصروف (للصادر)، أو أي حساب تاني (ماعدا حسابات العملاء والموردين والموظفين). ومصاريف الخدمة في التحويل الصادر بتتسجل مصروف "عمولات التحويل" (حساب 5208) | `ConfirmWalletTransaction` |
| الاقتراحات: عميل أو مورد بنفس رقم المحوِّل، وفاتورة في الفرع مستحق عليها نفس المبلغ في آخر 3 أيام | `WalletSuggestions` |
| إشعار على السيستم لكل اللي ليهم صلاحية التأكيد في الفرع | `WalletNotifier` |
| **الربط بـ QR:** كود بيستخدم مرة واحدة وصالح 10 دقايق، والموبايل بياخد token خاص بيه، وتقدر تفصله من الشاشة. والموبايل يقدر يغيّر فرعه برقم سري لحد عنده صلاحية `wallet.manage` | `DevicePairing` + `WalletDeviceResource` |
| الـ API: `pair` و `config` و `heartbeat` و `messages` و `transactions` و `targets` و `confirm` و `reject` و `device` | `routes/api.php` + `Api/WalletDeviceController` |
| **المطابقة:** شاشة أرقام المحافظ بتعرض "الرصيد على الموبايل" (من آخر رسالة) جنب "الرصيد في الحسابات"، وبيتلوّن أحمر لو فيه فرق | `WalletResource` |
| 3 صلاحيات: `wallet.view` و `wallet.confirm` و `wallet.manage` | |
| 448 test | `tests/Feature/Wallet` |

**على أي install موجود:** `php artisan migrate`، وبعدها `db:seed` لكل من `PermissionSeeder` و `ChartOfAccountsSeeder` و `ExpenseCategorySeeder` و `WalletTemplateSeeder`.

**التطبيق:** شوف `mobile/README.md`. اتكتب من غير Flutter SDK، فلسه **متعملوش build ولا اتجرّب على موبايل**.

**مؤجّل:**
- صيغ اتصالات كاش وأورنج كاش وInstaPay (محتاجين رسايل حقيقية).
- قراءة إشعارات التطبيقات كبديل للـ SMS.
- Push حقيقي (FCM أو Reverb). دلوقتي التطبيق بيعمل polling كل 20 ثانية، والسيستم بيعتمد على إشعارات الداتابيز.
- حالة "مستني تحويل" في شاشة نقطة البيع عشان التطابق يبقى أوتوماتيك مع الفاتورة المفتوحة.
- خيار التسجيل التلقائي من غير تأكيد.

## 27. سجل النشاط (Activity log)
- `spatie/laravel-activitylog` v5. Each business model uses `App\Support\Audit\Audited`. The log name is the morph alias. Only changed fields are stored (old → new). `created_at`, `updated_at`, `password`, `pin` and hidden fields are excluded.
- Not logged: lines and items, journal lines, stock movements and levels, and journal entries posted by documents (only `is_manual` entries are logged). `Contact::balance`, `Wallet::last_balance` and `WalletDevice::last_seen_at` are excluded.
- `LogActivity` action: stores `properties.label` (the record name, kept after the record is deleted), `ip` and `agent`.
- `AuthActivity`: login, logout and failed login under log `auth` (the username only, never the password).
- Wallet API: the causer is the phone (`wallet_device`). After the PIN is checked, the causer is that employee.
- The legacy import writes one `legacy_import` entry and turns off logging while it runs.
- Screens: **Settings ← Activity log** (permission `activity_log.view`, filters for user, section, action and date), and a **History** button on every View page.
- Cleanup: `activitylog:clean` runs daily (365 days, `config/activitylog.php`).

## 28. المساعدة داخل النظام (In-app help)
- Help files: `resources/help/{ar,en}/{key}.md`, written in فصحى. The first `# ` line is the title. The key follows the URL: `sales.index`, `settings.users.index`, `reports.sales`. If `{key}.md` is missing, the parent file is used (`sales.md`).
- `App\Support\Help\HelpTopics` resolves the file. A render hook `PAGE_HEADER_ACTIONS_BEFORE` in `AdminPanelProvider` shows a "?" button that opens a slide-over. A page without a file shows no button.
- `GettingStarted` page (Settings, slug `getting-started`): the setup checklist, ticked from the data. The navigation badge shows how many essential steps are left. Strings are in `lang/*/onboarding.php`.
- `HelpTest` fails on a help file that matches no page.
- Every sidebar page and resource list has help (enforced by `HelpTest`). The POS screen uses `pos.md` through `filament.pos.help-button`, shown both on the toolbar and on the open-shift screen.

## 29. دليل الاستخدام PDF
- `php artisan guide:build [--fresh] [--only=name…] [--no-shots]`:
  1. With `--fresh`, refills the separate guide database (`newpos.guide_database`, default `newpos_guide`) with `GuideSeeder`, which builds on DemoSeeder and adds employees, payroll, wallets and an open shift. The command refuses if the guide database is the client's database.
  2. Starts a temporary `php -S` server on that database.
  3. `node scripts/guide/shots.mjs` (puppeteer-core with the installed Chrome) takes the screenshots to `public/guide/img/*.jpg`. Elements get a red frame, and a numbered badge that matches the step number in the help file.
  4. `node scripts/guide/pdf.mjs` prints the route `guide.print` (`resources/views/guide/print.blade.php`; the parts are in `resources/help/guide.php`) to `public/guide/user-guide.pdf`.
- The screenshots are embedded in `resources/help/ar/*.md`, so they also appear in the in-app "?" help.
- Getting started → "تحميل دليل الاستخدام (PDF)" (shown when the file exists).
- When a screen changes, update its entry in `shots.mjs`, its text in the .md file, then run `guide:build`.

## 30. طلبات السلف
- `advance_requests` + `employee_profiles.user_id` (migration 2026_01_12_000010).
- `AdvanceRequestWorkflow`: request (one pending per employee) → approve (amount/installment editable) / reject → pay (GiveAdvance: PV + advance) / cancel.
- `AdvanceRequestNotifier`: bell notifications to approvers (`advances.approve`), payers (`advances.create`) and the requester.
- Screens: Payroll → طلبات السلف (`AdvanceRequestResource`), and the "سلفة" button in the POS (`requestAdvance`, `myAdvanceRequests`).
- Permissions: `advances.request` (cashier, storekeeper, accountant) and `advances.approve`.
- Also: the vouchers modal offers customer or supplier only (no employee / account), and expenses no longer have the "expense for" field.

## 31. الفرع هو الدرج
- The drawers screen was removed. `BranchDrawer::for($location)` returns the branch's only drawer and creates it if missing; `Location::booted` creates it with each new branch.
- `php artisan cash:branch-drawers` (`consolidate()`), also run at the end of a full legacy import: keeps the drawer with an open shift (else the most used), names it after the branch, switches off drawers with history and deletes unused ones.
- POS: the shift opens on a branch (`shiftBranches()`, `openShift($locationId)`), shows who has a shift open there, and shows the branch name in the header. Shift pages show the branch.

## 32. السنة المالية: العرض والقفل
- `App\Support\FiscalYear`:
  - The selected year is kept in the session. The year switcher (`GLOBAL_SEARCH_BEFORE`) posts to route `fiscal-year.switch`; a banner (`CONTENT_START`) shows while a past year is open.
  - `COLUMNS` says which date places each document in a year.
  - `ScopedToFiscalYear` on the document list pages limits them to the year (via `getTableQuery`).
  - Reports, the trial balance, contact statements and the account ledger default to the year's range.
- `App\Support\PeriodLock`:
  - Setting `general.lock_past_years` (default true); the year start comes from `general.fy_start_month`.
  - `Posting::sync`, `manual` and `reverse` refuse dates before the current fiscal year for authenticated users without the `fiscal_year.edit_closed` permission.
  - The console and imports are never locked.
- Also: `config/database.php` sets `engine` to `DB_ENGINE` (default InnoDB), because some hosts default to MyISAM.
