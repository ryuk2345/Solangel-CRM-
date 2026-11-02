<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Instalación desde cero: las migraciones de multisucursal (2026_10_05_*)
 * asumen que existe la tienda con id 1. En una base nueva no existe, así que
 * se crea aquí la "Tienda Principal" antes de que esas migraciones corran.
 * En una base que ya tiene tiendas no hace nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('branches')->exists()) {
            return;
        }

        DB::table('branches')->insert([
            'id'         => 1,
            'name'       => 'Tienda Principal',
            'sunat_code' => '0000',
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Solo se elimina si sigue siendo la tienda creada aquí y no tiene datos asociados.
        $hasData = DB::table('warehouses')->where('branch_id', 1)->exists()
            || DB::table('users')->where('branch_id', 1)->exists();

        if (!$hasData) {
            DB::table('branches')->where('id', 1)->where('name', 'Tienda Principal')->delete();
        }
    }
};
