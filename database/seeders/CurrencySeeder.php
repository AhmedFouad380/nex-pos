<?php

namespace Database\Seeders;

use App\Domain\Foundation\Models\Currency;
use Illuminate\Database\Seeder;

class CurrencySeeder extends Seeder
{
    public function run(): void
    {
        $currencies = [
            ['code' => 'EGP', 'name' => 'جنيه مصري', 'symbol' => 'ج.م', 'precision' => 2, 'symbol_position' => 'after'],
            ['code' => 'USD', 'name' => 'دولار أمريكي', 'symbol' => '$', 'precision' => 2, 'symbol_position' => 'before'],
            ['code' => 'EUR', 'name' => 'يورو', 'symbol' => '€', 'precision' => 2, 'symbol_position' => 'before'],
            ['code' => 'SAR', 'name' => 'ريال سعودي', 'symbol' => 'ر.س', 'precision' => 2, 'symbol_position' => 'after'],
            ['code' => 'AED', 'name' => 'درهم إماراتي', 'symbol' => 'د.إ', 'precision' => 2, 'symbol_position' => 'after'],
            ['code' => 'KWD', 'name' => 'دينار كويتي', 'symbol' => 'د.ك', 'precision' => 3, 'symbol_position' => 'after'],
            ['code' => 'CNY', 'name' => 'يوان صيني', 'symbol' => '¥', 'precision' => 2, 'symbol_position' => 'before'],
        ];

        foreach ($currencies as $currency) {
            Currency::query()->updateOrCreate(['code' => $currency['code']], $currency);
        }
    }
}
