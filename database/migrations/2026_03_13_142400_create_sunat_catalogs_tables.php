<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // CATÁLOGO 06: Tipos de Documento de Identidad (DNI, RUC, CE, etc.)
        Schema::create('sunat_document_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 2)->unique(); // Ej: '1', '6'
            $table->string('description'); // Ej: 'DNI', 'RUC'
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // CATÁLOGO 07: Códigos de Tipo de Afectación del IGV
        Schema::create('sunat_igv_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 2)->unique(); // Ej: '10', '20', '30'
            $table->string('description'); // Ej: 'Gravado - Operación Onerosa'
            $table->boolean('is_onerous')->default(true); // Para saber si suma al total a pagar
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sunat_igv_types');
        Schema::dropIfExists('sunat_document_types');
    }
};