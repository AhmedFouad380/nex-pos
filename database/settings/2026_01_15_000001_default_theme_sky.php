<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /** Sky blue becomes the default main colour: installs still on the old default (amber) switch to it. */
    public function up(): void
    {
        $this->migrator->update('general.theme_color', fn (?string $color) => in_array($color, [null, 'amber'], true) ? 'sky' : $color);
    }

    public function down(): void
    {
        $this->migrator->update('general.theme_color', fn (?string $color) => $color === 'sky' ? 'amber' : $color);
    }
};
