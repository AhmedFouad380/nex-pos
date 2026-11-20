<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Base data every installation needs. Every seeder is idempotent, so
 * `php artisan db:seed` is safe to run again on a live client install.
 * Demo data lives in DemoSeeder: `php artisan db:seed --class=DemoSeeder`.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            CurrencySeeder::class,
            CatalogDefaultsSeeder::class,
            ChartOfAccountsSeeder::class,
            PaymentMethodSeeder::class,
            PrintingSeeder::class,
            LocationSeeder::class,
            DocumentSequenceSeeder::class,
            ContactSeeder::class,
            CashDenominationSeeder::class,
            ExpenseCategorySeeder::class,
            ServiceTypeSeeder::class,
            WalletTemplateSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
