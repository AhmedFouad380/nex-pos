<?php

/*
 * The printable user guide (php artisan guide:build → public/guide/user-guide.pdf).
 * Parts by job, each a list of help keys from resources/help/{locale}/{key}.md, in reading order.
 */

return [
    [
        'title' => 'البداية',
        'intro' => 'نظرة عامة على النظام وخطوات تجهيزه قبل بدء العمل.',
        'topics' => ['getting-started', 'dashboard', 'settings.business', 'settings.locations', 'settings.users', 'settings.roles', 'settings.payment-methods'],
    ],
    [
        'title' => 'الكاشير ونقطة البيع',
        'intro' => 'البيع اليومي، والورديات (لكل فرع وردية واحدة مفتوحة في الوقت نفسه)، والفواتير، والمرتجعات.',
        'topics' => ['pos', 'cash-sessions', 'sales', 'sale-returns'],
    ],
    [
        'title' => 'المنتجات والمخزون',
        'intro' => 'تعريف المنتجات وأسعارها، ومتابعة المخزون في الفروع.',
        'topics' => ['products', 'catalog.categories', 'catalog.units', 'catalog.brands', 'catalog.price-groups', 'catalog.tax-rates',
            'catalog.variation-templates', 'catalog.warranties', 'stock-levels', 'stock-adjustments', 'stock-transfers', 'print-labels'],
    ],
    [
        'title' => 'المشتريات والموردون',
        'intro' => 'أوامر الشراء، واستلام البضاعة، والمرتجعات، وحسابات الموردين.',
        'topics' => ['purchase-orders', 'purchases', 'purchase-returns', 'suppliers'],
    ],
    [
        'title' => 'العملاء والحسابات',
        'intro' => 'حسابات العملاء، والسندات، والمصروفات، والحسابات العامة.',
        'topics' => ['customers', 'receipt-vouchers', 'payment-vouchers', 'expenses', 'expense-categories', 'account-transfers',
            'accounts', 'journal-entries', 'trial-balance', 'reports'],
    ],
    [
        'title' => 'الموظفون والمرتبات',
        'intro' => 'بيانات الموظفين، والسلف، ومسيرات الرواتب الشهرية.',
        'topics' => ['employees', 'employee-advances', 'payroll-runs'],
    ],
    [
        'title' => 'المحافظ الإلكترونية',
        'intro' => 'تسجيل تحويلات فودافون كاش وغيرها وتأكيدها.',
        'topics' => ['wallets', 'wallet-templates', 'wallet-devices', 'wallet-transactions'],
    ],
    [
        'title' => 'الطباعة والمتابعة',
        'intro' => 'تصميم الفواتير والملصقات، وترقيم المستندات، ومراجعة سجل النشاط.',
        'topics' => ['printing.invoice-layouts', 'printing.label-templates', 'settings.document-sequences', 'activity-log'],
    ],
];
