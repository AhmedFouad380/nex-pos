<?php

return [
    'fee_note' => 'مصاريف تحويل - :memo',
    'notify' => [
        'in' => 'وصل :amount جنيه على :provider من :from',
        'out' => 'اتحوّل :amount جنيه من :provider لـ :from',
        'confirm' => 'محتاج تأكيد قبل ما يتسجل سند.',
        'open' => 'فتح التحويلات',
    ],
    'suggest' => [
        'same_phone' => 'نفس رقم المحوِّل',
        'same_amount' => 'فاتورة مستحق عليها نفس المبلغ',
    ],
    'api' => [
        'due' => 'المستحق :amount',
    ],
    'errors' => [
        'unknown_placeholder' => 'الكلمة :name مش معروفة. المتاح: {amount} {fee} {balance} {phone} {name} {wallet} {datetime} {reference} {*}',
        'repeated_placeholder' => 'الكلمة :name مكتوبة أكتر من مرة.',
        'amount_required' => 'الصيغة لازم يكون فيها {amount}.',
        'not_pending' => 'التحويل ده اتأكد أو اترفض قبل كده.',
        'target_direction' => 'الاختيار ده مينفعش مع اتجاه التحويل (فاتورة البيع للوارد بس، والمصروف للصادر بس).',
        'not_allowed' => 'معندكش صلاحية على تحويلات الفرع ده.',
        'wallet_required' => 'اختار المحفظة اللي التحويل تم عليها.',
        'no_wallet_method' => 'مفيش طريقة دفع "محفظة إلكترونية" مفعّلة.',
        'sale_not_open' => 'الفاتورة دي مش نهائية أو ملغية.',
        'account_not_allowed' => 'مينفعش تختار الحساب ده. حسابات العملاء والموردين والموظفين بتتختار كجهة اتصال.',
        'pairing_code' => 'الكود غلط أو انتهى. اعمل QR جديد من السيستم.',
        'wrong_pin' => 'الرقم السري غلط.',
        'too_many_attempts' => 'محاولات كتير. استنى دقيقة.',
    ],
];
