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
    Schema::table('sales', function (Blueprint $table) {
        // Regla SUNAT: Forma de pago (Contado/Crédito)
        $table->string('payment_condition')->default('contado'); 
        
        // Control Interno: Medio de pago y Vuelto
        $table->string('payment_method')->default('efectivo'); 
        $table->decimal('amount_paid', 10, 2)->nullable(); // Con cuánto pagan
        $table->decimal('balance_returned', 10, 2)->nullable(); // El vuelto
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            //
        });
    }
};
