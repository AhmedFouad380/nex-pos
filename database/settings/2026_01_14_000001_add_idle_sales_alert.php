<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // a branch with an open shift and no sale for this many hours alerts the managers (0 = off)
        $this->migrator->add('pos.idle_alert_hours', 2);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('pos.idle_alert_hours');
    }
};
