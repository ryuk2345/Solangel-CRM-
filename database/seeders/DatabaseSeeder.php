<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     * Orden: Catálogos SUNAT → Empresa y series → Usuario Admin
     */
    public function run(): void
    {
        $this->call([
            SunatCatalogsSeeder::class,
            InitialSetupSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
