<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Deja una instalación nueva lista para vender en modo de pruebas:
 * configuración de la empresa con SUNAT en sandbox y las series básicas
 * de la Tienda Principal. Se puede ejecutar varias veces: nunca pisa datos existentes.
 */
class InitialSetupSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Configuración de la empresa (SUNAT siempre en sandbox al instalar)
        if (!DB::table('company_settings')->exists()) {
            $sunatSandbox = true;

            DB::table('company_settings')->insert([
                'ruc'           => env('EMPRESA_RUC') ?: '20000000001',
                'name'          => env('EMPRESA_NOMBRE') ?: 'EMPRESA DE PRUEBA S.A.C.',
                'address_principal' => env('EMPRESA_DIRECCION') ?: null,
                'sunat_sandbox' => $sunatSandbox,
                'cert_path'     => $sunatSandbox ? (env('SUNAT_CERT_PATH') ?: null) : null,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
        }

        // 2. Series de venta de la Tienda Principal (las de notas y guías las crea la migración 2026_10_05_000006)
        $branchId = DB::table('branches')->orderBy('id')->value('id');
        if (!$branchId) {
            return;
        }

        $series = [
            ['document_type' => '01', 'serie' => 'F001'], // Factura
            ['document_type' => '03', 'serie' => 'B001'], // Boleta
            ['document_type' => '00', 'serie' => 'NV01'], // Nota de venta interna
        ];

        foreach ($series as $s) {
            if (DB::table('series')->where('serie', $s['serie'])->exists()) {
                continue;
            }

            DB::table('series')->insert([
                'branch_id'      => $branchId,
                'document_type'  => $s['document_type'],
                'serie'          => $s['serie'],
                'current_number' => 1, // siguiente número a emitir (el sistema usa este y luego incrementa)
                'is_active'      => true,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
        }

        $this->command?->info('✅ Empresa (SUNAT en sandbox) y series F001, B001 y NV01 listas.');
    }
}
