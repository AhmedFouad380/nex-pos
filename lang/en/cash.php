<?php

return [
    'errors' => [
        'cannot_open' => 'You are not allowed to open a shift. Ask a manager to give your role the open shift permission.',
        'branch_not_allowed' => 'You cannot open a shift in this branch. Ask a manager to add it to your branches.',
        'register_inactive' => 'This drawer is inactive.',
        'register_busy' => 'Branch ":register" has a shift open by :user since :since. It must be closed first.',
        'user_has_session' => 'You already have an open shift.',
        'session_closed' => 'The shift is closed.',
        'no_session' => 'Open a shift first.',
        'not_enough_cash' => 'The amount is more than the cash in the drawer.',
        'suspended_sales' => 'There are suspended sales on this shift. Finish or delete them before closing.',
    ],
    'closed' => [
        'title' => 'Shift closed: :cashier — :register',
        'matched' => 'Cash matches',
        'short' => 'Short :amount',
        'over' => 'Over :amount',
        'body' => 'Counted :counted · expected :expected · shift invoices: :count totalling :total',
        'open' => 'View shift',
    ],
];
