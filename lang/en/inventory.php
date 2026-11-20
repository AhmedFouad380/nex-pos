<?php

return [
    'errors' => [
        'insufficient_stock' => 'Not enough stock of ":item": requested :requested, available :available.',
        'already_consumed' => 'Cannot reverse: part of the stock of ":item" was already sold or issued. Use a return document instead.',
        'invalid_unit' => 'Unit ":unit" is not allowed for ":item".',
        'not_stock_tracked' => '":item" does not track stock (service or combo).',
        'same_location' => 'Cannot transfer to the same location.',
        'invalid_status' => 'This action is not allowed on a document with status ":status".',
        'already_voided' => 'This document is already voided.',
        'no_items' => 'Add at least one item.',
        'non_positive_qty' => 'Quantity must be greater than zero.',
    ],
];
