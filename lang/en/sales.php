<?php

return [
    'errors' => [
        'final_is_immutable' => 'A final invoice cannot be edited. Use a return or void it.',
        'not_sellable' => '":item" is not available for sale.',
        'not_a_customer' => '":name" is not a customer.',
        'walk_in_must_pay' => 'The walk-in customer must pay in full. Choose a registered customer for credit.',
        'credit_not_allowed' => 'You are not allowed to sell on credit.',
        'credit_limit' => 'Customer credit limit exceeded (:limit).',
        'discount_too_high' => 'The discount exceeds your limit (:max%).',
        'invoice_below_cost' => 'The invoice discount takes the invoice below the cost of its items. Items cannot be sold below cost.',
        'invoice_below_cost_with' => 'The invoice discount takes the invoice below the cost of its items (:cost). Items cannot be sold below cost.',
        'below_cost' => 'The edited price of ":item" is below its cost. Items cannot be sold below cost.',
        'below_cost_with' => 'The edited price of ":item" is below its cost (:cost). Items cannot be sold below cost.',
        'overpaid_non_cash' => 'More than the total was tendered. Change can only be given in cash.',
        'has_returns' => 'The invoice has returns. Void them first.',
        'shared_payment' => 'A payment of this invoice also settles other documents. Void it from Payments.',
        'return_exceeds' => 'Returned quantity of ":item" exceeds what can be returned (:max).',
        'not_final' => 'This is not a final invoice.',
    ],
];
