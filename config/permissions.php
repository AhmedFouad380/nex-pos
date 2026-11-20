<?php

/*
|--------------------------------------------------------------------------
| Permissions & default roles — single source of truth
|--------------------------------------------------------------------------
| `php artisan db:seed --class=PermissionSeeder` syncs this file into the DB
| (safe to re-run on a live install: it only adds/updates, never removes
| permissions a client may have assigned).
| Labels are translation keys under lang/{ar,en}/permissions.php.
*/

return [
    'groups' => [
        'access' => ['admin.access', 'pos.access', 'locations.access_all'],
        'users' => ['users.view', 'users.create', 'users.update', 'users.delete', 'roles.manage'],
        'settings' => ['settings.manage', 'locations.manage', 'printing.manage', 'backup.manage', 'activity_log.view'],
        'products' => ['products.view', 'products.create', 'products.update', 'products.delete', 'products.import',
            'products.view_purchase_price', 'catalog.manage', 'discounts.manage', 'labels.print'],
        'inventory' => ['stock.view', 'stock_adjustments.view', 'stock_adjustments.create', 'stock_adjustments.void',
            'stock_transfers.view', 'stock_transfers.create', 'stock_transfers.receive', 'stock_counts.manage', 'opening_stock.manage'],
        'contacts' => ['customers.view', 'customers.view_own', 'customers.create', 'customers.update', 'customers.delete',
            'suppliers.view', 'suppliers.view_own', 'suppliers.create', 'suppliers.update', 'suppliers.delete',
            'contacts.ledger', 'contacts.import', 'ledger_discounts.manage', 'customer_groups.manage', 'loyalty.manage'],
        'sales' => ['sales.view', 'sales.view_own', 'sales.create', 'sales.update', 'sales.void',
            'sales.view_paid_only', 'sales.view_due_only', 'sales.import',
            'quotations.manage', 'drafts.manage', 'sales_orders.manage', 'sale_returns.view', 'sale_returns.create',
            'shipments.view', 'shipments.update', 'commission_agents.manage'],
        'pos' => ['pos.sell', 'pos.edit_price', 'pos.edit_discount', 'pos.edit_payment', 'pos.credit_sale',
            'pos.override_limits', 'pos.return', 'pos.reprint', 'pos.view_sales'],
        'purchases' => ['purchases.view', 'purchases.view_own', 'purchases.create', 'purchases.update', 'purchases.void',
            'purchase_orders.manage', 'purchase_requisitions.manage', 'purchase_returns.view', 'purchase_returns.create'],
        'finance' => ['payments.view', 'payments.create', 'payments.update', 'payments.void',
            'vouchers.receipt', 'vouchers.payment', 'expenses.view', 'expenses.create', 'expenses.update', 'expenses.void',
            'accounts.view', 'accounts.manage', 'journal.view', 'journal.manual', 'account_transfers.manage'],
        'payroll' => ['employees.view', 'employees.create', 'employees.update', 'employees.delete',
            'advances.view', 'advances.create', 'advances.void', 'advances.request', 'advances.approve',
            'payroll.view', 'payroll.create', 'payroll.approve', 'payroll.pay', 'payroll.void'],
        'wallet' => ['wallet.view', 'wallet.confirm', 'wallet.manage'],
        'cash' => ['cash_register.open', 'cash_register.close', 'cash_register.view', 'cash_register.close_others', 'cash_register.view_expected', 'cash_movements.create'],
        'restaurant' => ['restaurant.tables', 'restaurant.bookings', 'restaurant.kitchen', 'restaurant.orders'],
        'reports' => ['reports.profit_loss', 'reports.sales', 'reports.purchases', 'reports.stock', 'reports.tax',
            'reports.contacts', 'reports.payments', 'reports.expenses', 'reports.cash_register', 'reports.staff',
            'reports.trending', 'reports.accounting', 'dashboard.view'],
    ],

    // role name => permission names ('*' = everything). super-admin bypasses checks via Gate::before.
    'roles' => [
        'super-admin' => ['*'],
        'manager' => ['*'],
        'accountant' => [
            'admin.access', 'locations.access_all', 'dashboard.view', 'advances.request',
            'customers.view', 'suppliers.view', 'employees.view', 'employees.create', 'employees.update', 'contacts.ledger', 'ledger_discounts.manage',
            'sales.view', 'sale_returns.view', 'purchases.view', 'purchase_returns.view', 'products.view', 'products.view_purchase_price', 'stock.view',
            'payments.view', 'payments.create', 'payments.update', 'vouchers.receipt', 'vouchers.payment',
            'expenses.view', 'expenses.create', 'expenses.update', 'accounts.view', 'accounts.manage', 'journal.view', 'journal.manual',
            'account_transfers.manage', 'cash_register.view',
            'advances.view', 'advances.create', 'payroll.view', 'payroll.create', 'payroll.pay',
            'wallet.view', 'wallet.confirm',
            'reports.profit_loss', 'reports.sales', 'reports.purchases', 'reports.tax', 'reports.contacts', 'reports.payments',
            'reports.expenses', 'reports.cash_register', 'reports.accounting',
        ],
        'storekeeper' => [
            'admin.access', 'products.view', 'products.create', 'products.update', 'labels.print', 'stock.view', 'advances.request',
            'stock_adjustments.view', 'stock_adjustments.create', 'stock_transfers.view', 'stock_transfers.create', 'stock_transfers.receive',
            'stock_counts.manage', 'opening_stock.manage', 'purchases.view', 'purchases.create', 'purchase_orders.manage',
            'purchase_returns.view', 'purchase_returns.create', 'suppliers.view', 'reports.stock',
        ],
        'cashier' => [
            'pos.access', 'pos.sell', 'pos.reprint', 'pos.return', 'advances.request', 'wallet.view', 'wallet.confirm', 'sales.view_own', 'customers.view', 'customers.create',
            'cash_register.open', 'cash_register.close', 'cash_movements.create', 'drafts.manage', 'quotations.manage',
        ],
    ],
];
