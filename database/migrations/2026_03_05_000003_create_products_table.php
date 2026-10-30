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
    Schema::create('products', function (Blueprint $table) {
        $table->id();
        $table->foreignId('category_id')->constrained()->cascadeOnDelete();
        
        $table->string('name'); // Ej: Vestido de Noche Satén
        $table->text('description')->nullable();
        
        // --- NUEVO: PRECIOS BASE ---
        $table->decimal('purchase_price', 10, 2)->nullable(); // Costo (Lo que le cuesta a la boutique)
        $table->decimal('sale_price', 10, 2); // Precio de venta al público
        
        $table->string('material')->nullable(); // Ej: 100% Seda
        $table->string('badge')->nullable(); // Ej: NUEVO, MÁS VENDIDO
        $table->boolean('is_web_visible')->default(true); 
        
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
