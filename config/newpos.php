<?php

return [
    /*
    | First super admin created by AdminUserSeeder on a fresh install.
    | Set real values in .env before seeding a client's server, then change the password after first login.
    */
    'seed_admin' => [
        'email' => env('SEED_ADMIN_EMAIL', 'admin@newpos.test'),
        'password' => env('SEED_ADMIN_PASSWORD', 'password'),
    ],

    /*
    | Separate database filled with demo data for the user-guide screenshots (php artisan guide:build).
    | Never the client's database: the command refuses when both names are the same.
    */
    'guide_database' => env('GUIDE_DB_DATABASE', 'newpos_guide'),
];
