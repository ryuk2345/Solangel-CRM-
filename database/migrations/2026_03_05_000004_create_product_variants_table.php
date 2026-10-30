<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->string('sku')->unique(); // Ej: VST-SAT-ROJ-M
            $table->string('size'); // Ej: S, M, L, Única
            $table->string('color_name')->nullable(); // Ej: Rojo
            $table->string('color_hex')->nullable(); // Ej: #7f1d1d

            // --- NUEVO: PRECIOS POR VARIANTE (Opcionales) ---
            // Útil si una talla grande o un color especial tiene otro costo/precio
            $table->decimal('purchase_price', 10, 2)->nullable();
            $table->decimal('sale_price', 10, 2)->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
