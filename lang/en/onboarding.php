<?php

return [
    'intro' => [
        'title' => 'Set up the system before you start',
        'description' => 'This list updates itself: each step is ticked as soon as it is done. Start with the essential steps, in order.',
    ],
    'progress' => ':done of :total done',
    'review' => 'Review',
    'groups' => [
        'required' => 'Essential steps',
        'optional' => 'Depending on your business',
    ],
    'steps' => [
        'business' => ['title' => 'Business details', 'description' => 'Name, logo, address, phone, tax number, currency and time zone. Printed on invoices and vouchers.', 'action' => 'Enter details'],
        'locations' => ['title' => 'Locations', 'description' => 'Review the main location and add your other branches and warehouses.', 'action' => 'Manage locations'],
        'users' => ['title' => 'Users and permissions', 'description' => 'Create an account for every employee who uses the system, with a role and allowed locations.', 'action' => 'Add a user'],
        'products' => ['title' => 'Products', 'description' => 'Add products with prices, units and categories, or import them from Excel.', 'action' => 'Add a product'],
        'opening_stock' => ['title' => 'Opening stock', 'description' => 'Enter the quantities on hand with a stock adjustment (increase), or with a purchase.', 'action' => 'Stock adjustment'],
        'suppliers' => ['title' => 'Suppliers and opening balances', 'description' => 'Add suppliers and what you owe each of them on day one.', 'action' => 'Add a supplier'],
        'customers' => ['title' => 'Customers and opening balances', 'description' => 'Add credit customers, what they owe and their credit limit.', 'action' => 'Add a customer'],
        'employees' => ['title' => 'Employees and salaries', 'description' => 'Record employees, salaries and fixed allowances for advances and the monthly payroll.', 'action' => 'Add an employee'],
        'wallets' => ['title' => 'Mobile wallets', 'description' => 'Record your wallet numbers and pair the shop phones with the wallet app.', 'action' => 'Add a wallet'],
    ],
];
