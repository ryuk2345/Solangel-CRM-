<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     * Orden: Catálogos SUNAT → Empresa y series → Roles → Usuario Admin
     */
    public function run(): void
    {
        $this->call([
            SunatCatalogsSeeder::class,
            InitialSetupSeeder::class,
            RolesSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
