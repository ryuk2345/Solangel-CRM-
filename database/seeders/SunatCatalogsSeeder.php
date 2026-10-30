<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SunatCatalogsSeeder extends Seeder
{
    public function run(): void
    {
        // Llenando el Catálogo 06 (Documentos)
        DB::table('sunat_document_types')->insert([
            ['code' => '0', 'description' => 'DOC.TRIB.NO.DOM.SIN.RUC'],
            ['code' => '1', 'description' => 'Documento Nacional de Identidad (DNI)'],
            ['code' => '4', 'description' => 'Carnet de Extranjería'],
            ['code' => '6', 'description' => 'Registro Único de Contribuyentes (RUC)'],
            ['code' => '7', 'description' => 'Pasaporte'],
        ]);

        // Llenando el Catálogo 07 (Tipos de IGV básicos para la boutique)
        DB::table('sunat_igv_types')->insert([
            ['code' => '10', 'description' => 'Gravado - Operación Onerosa', 'is_onerous' => true],
            ['code' => '20', 'description' => 'Exonerado - Operación Onerosa', 'is_onerous' => true],
            ['code' => '30', 'description' => 'Inafecto - Operación Onerosa', 'is_onerous' => true],
            ['code' => '11', 'description' => 'Gravado – Retiro por premio (GRATIS)', 'is_onerous' => false],
        ]);
    }
}