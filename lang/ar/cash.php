<?php

return [
    'errors' => [
        'register_inactive' => 'هذا الدرج غير مفعّل.',
        'register_busy' => 'فرع ":register" عليه وردية مفتوحة باسم :user منذ :since. يجب قفلها أولًا.',
        'user_has_session' => 'لديك وردية مفتوحة بالفعل.',
        'session_closed' => 'الوردية مقفولة.',
        'no_session' => 'افتح وردية أولًا.',
        'not_enough_cash' => 'المبلغ أكبر من النقدية الموجودة في الدرج.',
        'suspended_sales' => 'يوجد فواتير معلقة على هذه الوردية. أنهِها أو احذفها قبل القفل.',
    ],
    'closed' => [
        'title' => 'قفل وردية :cashier — :register',
        'matched' => 'النقدية مطابقة',
        'short' => 'عجز :amount',
        'over' => 'زيادة :amount',
        'body' => 'المعدود :counted · المتوقع :expected · فواتير الوردية: :count بإجمالي :total',
        'open' => 'عرض الوردية',
    ],
];
