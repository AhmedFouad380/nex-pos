// Screenshots of the user guide. Run through `php artisan guide:build` (or: node scripts/guide/shots.mjs [name…]).
// Each shot: name (file public/guide/img/{name}.jpg), url, optional steps before the shot, callouts and target
// ('main' = page content, 'modal' = the open modal, 'viewport' = whole screen).
// Callouts with `n` carry the number of the matching step in resources/help/ar/*.md.
import { mkdirSync } from 'node:fs';
import { launch, login, settle, clickText, callouts, shoot, hideTopbar, BASE } from './browser.mjs';

const OUT = process.env.GUIDE_IMG ?? 'public/guide/img';
const open = (text) => async (page) => clickText(page, text);
const scan = (...skus) => async (page) => {
    for (const sku of skus) {
        await page.focus('.pos-search');
        await page.keyboard.type(sku);
        await page.keyboard.press('Enter');
        await settle(page, 700);
    }
};

export const SHOTS = [
    { name: 'getting-started', url: '/admin/getting-started' },
    { name: 'dashboard', url: '/admin', target: 'viewport' },

    // cash register
    { name: 'pos', url: '/pos/sell', target: 'viewport', before: scan('MLK-001', 'SNK-001', 'SNK-001'),
        callouts: [{ text: 'ابحث أو امسح الباركود…', n: 1 }, { text: 'اسم العميل أو رقم الموبايل…', n: 2 }, { text: 'دفع نقدي', n: 3 },
            { text: 'بطاقة', n: 4 }, { text: 'دفع متعدد', n: 5 }, { text: 'تعليق', n: 6 },
            { text: 'استرجاع', n: 7 }, { text: 'آخر الفواتير', n: 8 }, { text: 'إيداع / سحب نقدي', n: 9 }, { text: 'قفل الوردية', n: 10 }] },
    { name: 'pos-payment', url: '/pos/sell', target: 'viewport', before: async (page) => { await scan('MLK-001')(page); await clickText(page, 'دفع متعدد'); },
        callouts: [{ text: 'إضافة طريقة دفع', n: 1 }, { text: 'إتمام البيع', n: 2 }] },
    { name: 'cash-sessions-view', url: '/admin/cash-sessions/1' },

    // sales
    { name: 'sales', url: '/admin/sales', callouts: ['فتح نقطة البيع', 'إخفاء الملغي'] },
    { name: 'sales-view', url: '/admin/sales/3', callouts: ['تحصيل دفعة', 'طباعة', 'مرتجع', 'إلغاء', 'السجل'] },
    { name: 'sale-returns-create', url: '/admin/sale-returns/create?sale=3',
        callouts: [{ text: 'فاتورة', n: 1 }, { text: 'الكمية المرتجعة', n: 2 }, { text: 'يرجع للمخزون', n: 3 }, { text: 'رد المبلغ الآن بـ', n: 4 }, { text: 'السبب', n: 5 }] },

    // purchasing & inventory
    { name: 'purchases-create', url: '/admin/purchases/create',
        callouts: [{ text: 'المورد', n: 1 }, { text: 'رقم فاتورة المورد', n: 2 }, { text: 'تاريخ الاستحقاق', n: 3 }, { text: 'إضافة صنف', n: 4 },
            { text: 'الإجماليات', n: 6 }, { text: 'مصاريف إضافية (جمارك، نقل...)', n: 7 }, { text: 'المدفوع الآن', n: 8 }] },
    { name: 'purchases-view', url: '/admin/purchases/1', callouts: ['إضافة دفعة', 'مرتجع للمورد', 'طباعة ملصقات الباركود'] },
    { name: 'purchase-orders', url: '/admin/purchase-orders/create', callouts: ['المورد', 'موعد التوريد المتوقع', 'الأصناف'] },
    { name: 'products', url: '/admin/products', callouts: ['إضافة المنتج', { css: '.fi-ta-filters-trigger-action-ctn button, button[title="تصفية"]' }] },
    { name: 'products-create', url: '/admin/products/create',
        callouts: [{ text: 'النوع', n: 1 }, { text: 'كود الصنف', n: 2 }, { text: 'الوحدة', n: 3 }, { text: 'التسعير', n: 4 },
            { text: 'الباركود', n: 5 }, { text: 'يُباع / يُشترى أيضًا بـ', n: 6 }] },
    { name: 'products-view', url: '/admin/products/8', callouts: ['المخزون حسب الفرع'] },
    { name: 'stock-adjustments-create', url: '/admin/stock-adjustments/create',
        callouts: [{ text: 'نوع التسوية', n: 1 }, { text: 'الأصناف', n: 2 }, { text: 'السبب', n: 3 }] },
    { name: 'stock-transfers-create', url: '/admin/stock-transfers/create',
        callouts: [{ text: 'من فرع', n: 1 }, { text: 'إلى فرع', n: 1 }, { text: 'إرسال فوري (تخرج البضاعة من الفرع الآن)', n: 2 }] },
    { name: 'stock-levels', url: '/admin/stock-levels', callouts: ['متوسط التكلفة', 'قيمة المخزون'] },
    { name: 'print-labels', url: '/admin/print-labels', callouts: [{ text: 'ورق الملصقات', n: 1 }, { text: 'يظهر على الملصق', n: 2 }, { text: 'الأصناف', n: 3 }] },

    // contacts
    { name: 'customers-create', url: '/admin/customers/create',
        callouts: [{ text: 'الاسم', n: 1 }, { text: 'الحد الائتماني', n: 2 }, { text: 'مدة السداد', n: 3 }, { text: 'مجموعة العملاء', n: 4 },
            { text: 'الرصيد الافتتاحي', n: 5 }, { text: 'المستخدمون المسؤولون', n: 6 }] },
    { name: 'customers-view', url: '/admin/customers/4', callouts: ['تحصيل دفعة', 'كشف الحساب'] },
    { name: 'customers-statement', url: '/admin/customers/4/statement' },
    { name: 'suppliers-view', url: '/admin/suppliers/5', callouts: ['سداد للمورد', 'كشف الحساب'] },

    // employees & payroll
    { name: 'employees-view', url: '/admin/employees/8', callouts: ['بيانات المرتب', 'السلف المفتوحة', 'صرف سلفة', 'كشف الحساب'] },
    { name: 'employee-advances-give', url: '/admin/employee-advances', before: open('صرف سلفة'), target: 'modal',
        callouts: [{ text: 'المبلغ', n: 2 }, { text: 'طريقة الدفع', n: 2 }, { text: 'القسط الشهري', n: 3 }] },
    { name: 'payroll-runs-edit', url: '/admin/payroll-runs/2/edit', width: 1920,
        callouts: [{ text: 'السلف المفتوحة' }, { text: 'الإضافي', n: 3 }, { text: 'أيام الغياب', n: 3 }, { text: 'خصم السلفة', n: 3 }, { text: 'الصافي' }, { text: 'تاريخ القيد', n: 5 }] },
    { name: 'payroll-runs-view', url: '/admin/payroll-runs/1', callouts: ['طباعة'] },
    { name: 'payroll-runs-draft', url: '/admin/payroll-runs/2', callouts: ['اعتماد', 'تعديل المسير'] },

    // finance
    { name: 'receipt-vouchers-new', url: '/admin/receipt-vouchers', before: open('سند قبض جديد'), target: 'modal',
        callouts: [{ text: 'استلمنا من', n: 2 }, { text: 'المبلغ', n: 3 }, { text: 'طريقة الدفع', n: 3 }, { text: 'تسوية أقدم الفواتير المفتوحة تلقائيًا', n: 4 }] },
    { name: 'receipt-vouchers', url: '/admin/receipt-vouchers', callouts: ['سند قبض جديد', 'الإجمالي'] },
    { name: 'payment-vouchers-new', url: '/admin/payment-vouchers', before: open('سند صرف جديد'), target: 'modal',
        callouts: [{ text: 'صرفنا إلى', n: 2 }, { text: 'المبلغ', n: 3 }, { text: 'تسوية أقدم الفواتير المفتوحة تلقائيًا', n: 4 }] },
    { name: 'expenses-create', url: '/admin/expenses/create',
        callouts: [{ text: 'بند المصروف', n: 1 }, { text: 'المبلغ قبل الضريبة', n: 2 }, { text: 'الدفع', n: 3 }] },
    { name: 'accounts', url: '/admin/accounts', callouts: ['دفتر الأستاذ'] },
    { name: 'journal-entries-create', url: '/admin/journal-entries/create', callouts: [{ text: 'البنود', n: 2 }, { text: 'مدين', n: 2 }, { text: 'دائن', n: 2 }] },
    { name: 'account-transfers-new', url: '/admin/account-transfers', before: open('تحويل جديد'), target: 'modal',
        callouts: [{ text: 'من', n: 2 }, { text: 'إلى', n: 2 }, { text: 'المبلغ', n: 3 }] },
    { name: 'trial-balance', url: '/admin/trial-balance' },
    { name: 'reports-sales', url: '/admin/reports/sales', callouts: ['تصدير إلى Excel'] },

    // wallets
    { name: 'wallet-transactions', url: '/admin/wallet-transactions', callouts: ['تأكيد'] },
    { name: 'wallet-transactions-confirm', url: '/admin/wallet-transactions/1', before: open('تأكيد'), target: 'modal',
        callouts: [{ text: 'التحويل ده لـ', n: 2 }] },
    { name: 'wallets', url: '/admin/wallets', callouts: ['الرصيد على الموبايل', 'الرصيد في الحسابات'] },
    { name: 'wallet-devices-qr', url: '/admin/wallet-devices', before: async (page) => { await clickText(page, 'ربط موبايل'); await clickText(page, 'إظهار QR'); }, target: 'modal' },
    { name: 'wallet-templates', url: '/admin/wallet-templates', before: open('إضافة صيغة رسالة'), target: 'modal',
        callouts: [{ text: 'الراسل', n: 2 }, { text: 'القالب', n: 3 }, { text: 'شكل التاريخ', n: 4 }, { text: 'جرّب برسالة حقيقية', n: 5 }] },

    // settings
    { name: 'settings-business', url: '/admin/settings/business' },
    { name: 'settings-users-new', url: '/admin/settings/users', before: open('إضافة مستخدم'), target: 'modal',
        callouts: [{ text: 'كلمة المرور', n: 1 }, { text: 'رقم سري لنقطة البيع', n: 2 }, { text: 'الأدوار', n: 3 }, { text: 'الفروع', n: 4 }, { text: 'أقصى خصم مسموح', n: 5 }] },
    { name: 'settings-roles', url: '/admin/settings/roles' },
    { name: 'printing-invoice-layouts', url: '/admin/printing/invoice-layouts', before: open('إضافة قالب الفاتورة'), target: 'modal',
        callouts: [{ text: 'الورق', n: 1 }, { text: 'ما يظهر في الطباعة', n: 3 }, { text: 'رأس وتذييل الصفحة', n: 4 }] },
    { name: 'activity-log', url: '/admin/activity-log' },
    { name: 'catalog-units', url: '/admin/catalog/units' },
];

const only = process.argv.slice(2);
mkdirSync(OUT, { recursive: true });
const { browser, page } = await launch();
await login(page);
let failed = 0;
for (const shot of SHOTS.filter((s) => only.length === 0 || only.includes(s.name))) {
    try {
        await page.setViewport({ width: shot.width ?? 1440, height: 900, deviceScaleFactor: 1.5 });
        await page.goto(BASE + shot.url, { waitUntil: 'networkidle0' });
        await settle(page);
        if (shot.before) await shot.before(page);
        if ((shot.target ?? 'main') === 'main') await hideTopbar(page);
        const missing = await callouts(page, shot.callouts ?? []);
        await shoot(page, `${OUT}/${shot.name}.jpg`, shot.target ?? 'main');
        console.log(missing.length ? `~ ${shot.name} (not found: ${missing.join(' | ')})` : `✓ ${shot.name}`);
    } catch (e) {
        failed++;
        console.log(`✗ ${shot.name}: ${e.message.split('\n')[0]}`);
    }
}
await browser.close();
process.exit(failed ? 1 : 0);
