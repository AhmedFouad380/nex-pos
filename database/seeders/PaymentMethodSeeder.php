<?php

namespace Database\Seeders;

use App\Domain\Finance\Enums\PaymentMethodType;
use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $account = fn (string $code) => Account::query()->where('code', $code)->value('id');

        $methods = [
            ['نقدي', PaymentMethodType::Cash, '1101'],
            ['بطاقة / شبكة', PaymentMethodType::Card, '1102'],
            ['تحويل بنكي', PaymentMethodType::BankTransfer, '1102'],
            ['محفظة إلكترونية / إنستاباي', PaymentMethodType::Wallet, '1103'],
            ['شيك', PaymentMethodType::Cheque, '1105'],
            ['من الرصيد المقدم', PaymentMethodType::Advance, null],
        ];

        foreach ($methods as $sort => [$name, $type, $code]) {
            PaymentMethod::query()->firstOrCreate(['type' => $type, 'name' => $name], [
                'account_id' => $code ? $account($code) : null,
                'is_active' => true,
                'sort' => $sort,
            ]);
        }
    }
}
