<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Agregar índice único a series.serie (si no existe)
        Schema::table('series', function (Blueprint $table) {
            $table->unique('serie', 'series_serie_unique');
        });

        // 2. Asegurar que la Sucursal 1 tenga sunat_code = '0000'
        DB::table('branches')->where('id', 1)->whereNull('sunat_code')->update(['sunat_code' => '0000']);

        // 3. Obtener el ID de venta más alto de forma dinámica al ejecutarse (sin número fijo)
        $maxVentaId = (int) DB::table('sales')->max('id');
        $siguienteNumeroVenta = $maxVentaId + 1;

        // Obtener el mayor correlativo de guías existente de forma dinámica (o 1 si no hay)
        $maxGuiaCorrelative = (int) DB::table('despatches')->max('correlative');
        $siguienteNumeroGuia = $maxGuiaCorrelative > 0 ? ($maxGuiaCorrelative + 1) : 1;

        // 4. Notas de Crédito (Tipo 07) para Sucursal 1 (no duplicar si ya existen)
        if (!DB::table('series')->where('serie', 'FC01')->exists()) {
            DB::table('series')->insert([
                'branch_id' => 1,
                'document_type' => '07',
                'serie' => 'FC01',
                'current_number' => $siguienteNumeroVenta,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (!DB::table('series')->where('serie', 'BC01')->exists()) {
            DB::table('series')->insert([
                'branch_id' => 1,
                'document_type' => '07',
                'serie' => 'BC01',
                'current_number' => $siguienteNumeroVenta,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 5. Notas de Débito (Tipo 08) para Sucursal 1
        if (!DB::table('series')->where('serie', 'FD01')->exists()) {
            DB::table('series')->insert([
                'branch_id' => 1,
                'document_type' => '08',
                'serie' => 'FD01',
                'current_number' => $siguienteNumeroVenta,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (!DB::table('series')->where('serie', 'BD01')->exists()) {
            DB::table('series')->insert([
                'branch_id' => 1,
                'document_type' => '08',
                'serie' => 'BD01',
                'current_number' => $siguienteNumeroVenta,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // 6. Guías de Remisión Remitente (Tipo 09) para Sucursal 1
        if (!DB::table('series')->where('serie', 'T001')->exists()) {
            DB::table('series')->insert([
                'branch_id' => 1,
                'document_type' => '09',
                'serie' => 'T001',
                'current_number' => $siguienteNumeroGuia,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('series')->where('branch_id', 1)->whereIn('serie', ['FC01', 'BC01', 'FD01', 'BD01', 'T001'])->delete();

        Schema::table('series', function (Blueprint $table) {
            $table->dropUnique('series_serie_unique');
        });
    }
};
