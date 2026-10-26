<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Seeding never provisions, promotes, or changes authentication accounts.
        $this->command?->info('No authentication accounts seeded. Run php artisan admin:create to create your own Super Admin.');
    }
}
