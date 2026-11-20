<?php

namespace Database\Seeders;

use App\Domain\Printing\Enums\NotificationChannel;
use App\Domain\Printing\Enums\PaperSize;
use App\Domain\Printing\Models\InvoiceLayout;
use App\Domain\Printing\Models\NotificationTemplate;
use Illuminate\Database\Seeder;

class PrintingSeeder extends Seeder
{
    public function run(): void
    {
        $common = [
            'show_logo' => true, 'show_business_name' => true, 'show_location_name' => true, 'show_mobile_number' => true,
            'show_customer' => true, 'show_tax_number' => true, 'show_sku' => false, 'show_brand' => false,
            'show_previous_balance' => true, 'show_payments' => true, 'show_barcode' => false, 'show_qr_code' => false,
            'header_text' => null, 'footer_text' => 'شكرًا لتعاملكم معنا',
            'labels' => [
                'invoice_heading' => 'فاتورة', 'quotation_heading' => 'عرض سعر', 'return_heading' => 'مرتجع',
                'invoice_no' => 'رقم الفاتورة', 'date' => 'التاريخ', 'customer' => 'العميل', 'product' => 'الصنف',
                'qty' => 'الكمية', 'unit_price' => 'السعر', 'subtotal' => 'الإجمالي', 'discount' => 'الخصم',
                'tax' => 'الضريبة', 'total' => 'الإجمالي النهائي', 'paid' => 'المدفوع', 'due' => 'المتبقي',
                'change' => 'الباقي', 'previous_balance' => 'الرصيد السابق', 'cashier' => 'الكاشير',
            ],
        ];

        InvoiceLayout::query()->updateOrCreate(['name' => 'فاتورة A4'], [
            'paper' => PaperSize::A4, 'design' => 'classic', 'settings' => $common, 'is_default' => true,
        ]);
        InvoiceLayout::query()->updateOrCreate(['name' => 'إيصال حراري 80 مم'], [
            'paper' => PaperSize::Thermal80, 'design' => 'slim', 'settings' => [...$common, 'show_brand' => false, 'show_previous_balance' => false],
        ]);

        $templates = [
            ['new_sale', NotificationChannel::Whatsapp, null,
                "أهلًا {contact_name}،\nشكرًا لتعاملك مع {business_name}.\nفاتورة رقم {invoice_number} بقيمة {total_amount}.\nالمتبقي: {due_amount}\nرابط الفاتورة: {invoice_url}"],
            ['new_sale', NotificationChannel::Mail, 'فاتورة رقم {invoice_number} من {business_name}',
                "عزيزي {contact_name}،\n\nمرفق فاتورة رقم {invoice_number} بقيمة {total_amount}.\nيمكنك عرضها من الرابط: {invoice_url}\n\nمع تحيات {business_name}"],
            ['payment_received', NotificationChannel::Whatsapp, null,
                'أهلًا {contact_name}، تم استلام مبلغ {payment_amount} بتاريخ {payment_date}. رصيدك الحالي: {contact_balance}. {business_name}'],
            ['payment_reminder', NotificationChannel::Whatsapp, null,
                'تذكير من {business_name}: فاتورة رقم {invoice_number} عليها مبلغ متبقي {due_amount} مستحق بتاريخ {due_date}. رابط الدفع: {payment_url}'],
            ['payment_reminder', NotificationChannel::Sms, null,
                '{business_name}: فاتورة {invoice_number} متبقي {due_amount} مستحق {due_date}.'],
            ['new_quotation', NotificationChannel::Whatsapp, null,
                'أهلًا {contact_name}، عرض السعر رقم {quotation_number} بقيمة {total_amount} صالح حتى {valid_until}: {quotation_url}'],
        ];

        foreach ($templates as [$event, $channel, $subject, $body]) {
            NotificationTemplate::query()->firstOrCreate(
                ['event' => $event, 'channel' => $channel],
                ['subject' => $subject, 'body' => $body, 'auto_send' => false, 'is_active' => true],
            );
        }
    }
}
