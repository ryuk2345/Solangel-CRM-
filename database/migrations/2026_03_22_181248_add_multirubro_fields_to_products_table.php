<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Modificamos la tabla principal de Productos
        Schema::table('products', function (Blueprint $table) {
            // Switch para saber si es servicio (por defecto será producto físico falso)
            $table->boolean('is_service')->default(false)->after('name');
            // Código de unidad de SUNAT (por defecto NIU)
            $table->string('unit_code', 5)->default('NIU')->after('is_service');
        });

        // 2. Modificamos la tabla de Variantes (Tallas/Colores ahora son opcionales)
        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('size')->nullable()->change();
            $table->string('color_name')->nullable()->change();
            // Si tenías un campo para el código del color, también lo hacemos opcional
            if (Schema::hasColumn('product_variants', 'color_hex')) {
                $table->string('color_hex')->nullable()->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['is_service', 'unit_code']);
        });

        Schema::table('product_variants', function (Blueprint $table) {
            // Nota: Revertir un change() a required puede fallar si hay nulos, 
            // pero lo dejamos preparado por buenas prácticas.
            $table->string('size')->nullable(false)->change();
            $table->string('color_name')->nullable(false)->change();
        });
    }
};