<?php

namespace Database\Seeders;

use App\Domain\Foundation\Models\Location;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * First super admin. Credentials come from config/newpos.php (SEED_ADMIN_EMAIL / SEED_ADMIN_PASSWORD);
 * the defaults are for local development only — change the password after first login.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('newpos.seed_admin.email');

        $user = User::query()->firstOrCreate(['email' => $email], [
            'name' => 'مدير النظام',
            'username' => 'admin',
            'password' => config('newpos.seed_admin.password'),
            'language' => 'ar',
            'is_active' => true,
            'allow_login' => true,
        ]);

        $user->assignRole(User::SUPER_ADMIN_ROLE);
        $user->locations()->syncWithoutDetaching(Location::query()->pluck('id'));
    }
}
