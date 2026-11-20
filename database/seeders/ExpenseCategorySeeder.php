<?php

namespace Database\Seeders;

use App\Domain\Finance\Models\Account;
use App\Domain\Finance\Models\ExpenseCategory;
use Illuminate\Database\Seeder;

class ExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['إيجار', '5201'], ['رواتب وأجور', '5202'], ['كهرباء ومياه', '5203'], ['نقل ومواصلات', '5204'],
            ['صيانة', '5205'], ['اتصالات وإنترنت', '5206'], ['دعاية وإعلان', '5207'], ['عمولات التحويل', '5208'], ['مصروفات متنوعة', '5299'],
        ];

        foreach ($categories as [$name, $code]) {
            ExpenseCategory::query()->firstOrCreate(['name' => $name, 'parent_id' => null], [
                'code' => $code,
                'account_id' => Account::query()->where('code', $code)->value('id'),
            ]);
        }
    }
}
