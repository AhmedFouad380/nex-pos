<?php

return [
    'fee_note' => 'Transfer fee - :memo',
    'commission_note' => 'Wallet service commission: :memo',
    'transfer_note' => 'Transfer service for a customer: :memo',
    'withdraw_note' => 'Withdrawal service for a customer: :memo',
    'notify' => [
        'in' => ':amount EGP received on :provider from :from',
        'out' => ':amount EGP sent from :provider to :from',
        'confirm' => 'Needs confirmation before a voucher is recorded.',
        'open' => 'Open transfers',
    ],
    'suggest' => [
        'same_phone' => 'Same phone number',
        'same_amount' => 'Invoice due for the same amount',
    ],
    'api' => [
        'due' => 'Due :amount',
    ],
    'errors' => [
        'no_open_shift' => 'Open a shift in this branch first: the service cash goes into its drawer.',
        'no_cash_method' => 'There is no active cash payment method.',
        'bad_commission' => 'Wrong commission: it cannot be negative or more than the withdrawal.',
        'unknown_placeholder' => 'Unknown placeholder :name. Available: {amount} {fee} {balance} {phone} {name} {wallet} {datetime} {reference} {*}',
        'repeated_placeholder' => ':name appears more than once.',
        'amount_required' => 'The template must contain {amount}.',
        'not_pending' => 'This transfer was already confirmed or rejected.',
        'target_direction' => 'This choice does not fit the transfer direction (sale invoices are for money in, expenses for money out).',
        'not_allowed' => 'You may not handle this branch\'s transfers.',
        'wallet_required' => 'Choose the wallet the transfer went through.',
        'no_wallet_method' => 'No active "mobile wallet" payment method.',
        'sale_not_open' => 'This invoice is not final or is voided.',
        'account_not_allowed' => 'This account cannot be used. Customers, suppliers and employees are chosen as a contact.',
        'pairing_code' => 'Wrong or expired code. Make a new QR code in the system.',
        'wrong_pin' => 'Wrong PIN.',
        'too_many_attempts' => 'Too many attempts. Wait a minute.',
    ],
];
