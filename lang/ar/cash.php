<?php

return [
    'errors' => [
        'cannot_open' => 'ليس لديك صلاحية فتح وردية. اطلب من المدير تفعيل صلاحية فتح الوردية لدورك.',
        'branch_not_allowed' => 'لا يمكنك فتح وردية في هذا الفرع. اطلب من المدير إضافته إلى فروعك.',
        'register_inactive' => 'هذا الدرج غير مفعّل.',
        'register_busy' => 'فرع ":register" عليه وردية مفتوحة باسم :user منذ :since. يجب قفلها أولًا.',
        'user_has_session' => 'لديك وردية مفتوحة بالفعل.',
        'session_closed' => 'الوردية مقفولة.',
        'no_session' => 'افتح وردية أولًا.',
        'no_open_shift' => 'لا توجد وردية مفتوحة اليوم في فرع :branch. افتح وردية أولًا لتصرف أو تقبض نقدًا من خزنة الفرع.',
        'not_enough_cash' => 'المبلغ أكبر من النقدية الموجودة في الدرج.',
        'suspended_sales' => 'يوجد فواتير معلقة على هذه الوردية. أنهِها أو احذفها قبل القفل.',
    ],
    'kinds' => [
        'sale_return' => 'مرتجع بيع',
        'employee_advance' => 'سلفة موظف',
        'expense' => 'مصروف',
        'purchase' => 'دفعة فاتورة شراء',
        'purchase_return' => 'مرتجع شراء',
        'receipt' => 'سند قبض',
        'payment' => 'سند صرف',
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
