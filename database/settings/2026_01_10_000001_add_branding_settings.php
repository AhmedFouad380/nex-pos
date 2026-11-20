<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // business logo on invoices, receipts and vouchers (path on the public disk)
        $this->migrator->add('general.logo_path', null);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('general.logo_path');
    }
};
