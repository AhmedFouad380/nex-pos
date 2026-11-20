<?php

namespace Database\Seeders;

use App\Domain\CashManagement\Models\CashDenomination;
use Illuminate\Database\Seeder;

/** Egyptian pound notes & coins. */
class CashDenominationSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([200, 100, 50, 20, 10, 5, 1, 0.5, 0.25] as $sort => $value) {
            CashDenomination::query()->firstOrCreate(['value' => $value], ['is_active' => true, 'sort' => $sort]);
        }
    }
}
