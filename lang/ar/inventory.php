<?php

return [
    'errors' => [
        'insufficient_stock' => 'الكمية غير كافية من ":item": المطلوب :requested والمتاح :available.',
        'already_consumed' => 'لا يمكن إلغاء الحركة لأن جزءًا من رصيد ":item" تم بيعه أو صرفه بالفعل. استخدم مستند مرتجع بدلًا من ذلك.',
        'invalid_unit' => 'الوحدة ":unit" غير مسموحة للصنف ":item".',
        'not_stock_tracked' => 'الصنف ":item" لا يُتابع مخزونه (خدمة أو كومبو).',
        'same_location' => 'لا يمكن التحويل لنفس الفرع.',
        'invalid_status' => 'لا يمكن تنفيذ هذا الإجراء على مستند حالته ":status".',
        'already_voided' => 'هذا المستند ملغي بالفعل.',
        'no_items' => 'أضف صنفًا واحدًا على الأقل.',
        'non_positive_qty' => 'الكمية يجب أن تكون أكبر من صفر.',
    ],
];
