<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // years before the current fiscal year accept no new, edited or voided documents
        $this->migrator->add('general.lock_past_years', true);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('general.lock_past_years');
    }
};
