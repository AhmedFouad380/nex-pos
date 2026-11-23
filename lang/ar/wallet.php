<?php

return [
    'fee_note' => 'مصاريف تحويل - :memo',
    'commission_note' => 'عمولة خدمة المحافظ: :memo',
    'transfer_note' => 'خدمة تحويل لعميل: :memo',
    'withdraw_note' => 'خدمة سحب لعميل: :memo',
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
    'limit' => [
        'warn' => 'الخط :line استخدم :percent% من حد :period',
        'full' => 'الخط :line وصل لحد :period',
        'day' => 'التحويل اليومي',
        'month' => 'التحويل الشهري',
        'body' => 'المحوَّل :used من :limit. المتاح للتحويل الآن :available — حوّل من خط آخر.',
    ],
    'errors' => [
        'no_open_shift' => 'افتح وردية في هذا الفرع أولًا: نقدية الخدمة تدخل درج الوردية.',
        'no_cash_method' => 'لا توجد طريقة دفع نقدية نشطة.',
        'bad_commission' => 'العمولة غير صحيحة: لا تكون سالبة، ولا أكبر من مبلغ السحب.',
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
