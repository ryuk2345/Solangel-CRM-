<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 3 — Catálogo de moda (PLAN_MODA §3).
 * Colores, tallas, curvas de tallas y temporadas como catálogos; variantes por
 * color × talla con código de barras propio y dos precios (minorista y mayorista).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seasons', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();          // Ej: Verano 2027
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('colors', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();          // Ej: Azul marino
            $table->string('code', 6)->unique();       // Ej: AZM (se usa en el SKU)
            $table->string('hex', 7)->nullable();      // Ej: #1E3A8A
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('sizes', function (Blueprint $table) {
            $table->id();
            $table->string('name', 20)->unique();      // Ej: M, 32, 38
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('size_curves', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();          // Ej: Letras S–XL
            $table->timestamps();
        });

        Schema::create('size_curve_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('size_curve_id')->constrained('size_curves')->cascadeOnDelete();
            $table->foreignId('size_id')->constrained('sizes')->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unique(['size_curve_id', 'size_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('code', 20)->nullable()->unique()->after('id');   // código del modelo, base del SKU
            $table->foreignId('season_id')->nullable()->after('brand_id')->constrained('seasons')->nullOnDelete();
            $table->decimal('wholesale_price', 10, 2)->nullable()->after('sale_price');
            $table->unsignedInteger('low_stock_threshold')->nullable()->after('wholesale_price');
            $table->boolean('allows_delivery')->default(false)->after('is_web_visible');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->foreignId('color_id')->nullable()->after('product_id')->constrained('colors')->restrictOnDelete();
            $table->foreignId('size_id')->nullable()->after('color_id')->constrained('sizes')->restrictOnDelete();
            $table->string('barcode', 13)->nullable()->unique()->after('sku');
            $table->decimal('wholesale_price', 10, 2)->nullable()->after('sale_price');
            $table->boolean('is_active')->default(true)->after('wholesale_price');
            $table->unique(['product_id', 'color_id', 'size_id'], 'variants_product_color_size_unique');
        });

        Schema::table('product_images', function (Blueprint $table) {
            $table->foreignId('color_id')->nullable()->after('product_id')->constrained('colors')->nullOnDelete();
        });

        // Reglas del negocio configurables (decisiones 9 y 10 del cliente)
        Schema::table('company_settings', function (Blueprint $table) {
            $table->unsignedInteger('wholesale_min_qty')->default(2);     // prendas para precio mayorista
            $table->unsignedInteger('low_stock_threshold')->default(20);  // stock bajo por talla y color
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn(['wholesale_min_qty', 'low_stock_threshold']);
        });

        Schema::table('product_images', function (Blueprint $table) {
            $table->dropConstrainedForeignId('color_id');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropUnique('variants_product_color_size_unique');
            $table->dropConstrainedForeignId('color_id');
            $table->dropConstrainedForeignId('size_id');
            $table->dropUnique(['barcode']);
            $table->dropColumn(['barcode', 'wholesale_price', 'is_active']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('season_id');
            $table->dropUnique(['code']);
            $table->dropColumn(['code', 'wholesale_price', 'low_stock_threshold', 'allows_delivery']);
        });

        Schema::dropIfExists('size_curve_items');
        Schema::dropIfExists('size_curves');
        Schema::dropIfExists('sizes');
        Schema::dropIfExists('colors');
        Schema::dropIfExists('seasons');
    }
};
