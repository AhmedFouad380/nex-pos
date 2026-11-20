<?php

namespace Database\Seeders;

use App\Domain\Finance\Enums\PaymentDirection;
use App\Domain\Wallet\Enums\WalletProvider;
use App\Domain\Wallet\Models\WalletMessageTemplate;
use Illuminate\Database\Seeder;

/**
 * Message templates known to work, taken from real messages. Added once; a client's own edits are kept.
 * Others (Etisalat / Orange Cash, InstaPay banks) are added from the admin panel with "test template".
 */
class WalletTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [WalletProvider::VodafoneCash, PaymentDirection::In, 'فودافون كاش - استلام', 'VF-Cash',
                'تم استلام مبلغ {amount} جنيه من {phone}؛ المسجل بإسم {name} على رقم محفظتك {wallet} بتاريخ {datetime}.{*}رصيدك الحالي: {balance} جنيه{*}رقم العملية: {reference}'],
            [WalletProvider::VodafoneCash, PaymentDirection::Out, 'فودافون كاش - تحويل', 'VF-Cash',
                'تم تحويل {amount} جنيه لرقم {phone} مصاريف الخدمة {fee} جنيه رصيد حسابك فى فودافون كاش الحالي {balance}.{*}تاريخ العملية {datetime}{*}رقم العملية {reference}'],
        ];

        foreach ($templates as $sort => [$provider, $direction, $name, $sender, $template]) {
            WalletMessageTemplate::query()->firstOrCreate(['provider' => $provider, 'name' => $name], [
                'direction' => $direction, 'sender' => $sender, 'template' => $template, 'date_format' => 'H:i y-m-d', 'sort' => $sort,
            ]);
        }
    }
}
