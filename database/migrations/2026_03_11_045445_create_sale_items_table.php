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
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            // Si se borra la venta, se borra su detalle (cascadeOnDelete)
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            // El producto vendido (Para luego bajar el stock)
            $table->foreignId('product_variant_id')->constrained('products');
            
            // Guardamos el nombre por si en el futuro le cambian el nombre al producto en el catálogo
            $table->string('product_name'); 
            
            $table->integer('quantity'); // Cantidad (Ej: 2 blusas)
            $table->decimal('price', 10, 2); // Precio unitario
            $table->decimal('igv', 10, 2); // IGV de esta línea
            $table->decimal('total', 10, 2); // Subtotal de esta línea
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
