<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            MaterialSeeder::class,
            ShippingMethodSeeder::class,
            SettingsSeeder::class,
            UserSeeder::class,
            CatalogSeeder::class,
        ]);
    }
}
