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
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers'); // El cliente que compra
            $table->foreignId('user_id')->constrained('users'); // El vendedor (Tú o Brisza)
            
            // Datos del Comprobante (SUNAT)
            $table->string('document_type'); // '01' Factura, '03' Boleta
            $table->string('serie'); // Ej: B001
            $table->string('correlative'); // Ej: 000001
            $table->date('issue_date'); // Fecha de emisión
            
            // Totales (Con 10 dígitos y 2 decimales)
            $table->decimal('subtotal', 10, 2);
            $table->decimal('igv', 10, 2);
            $table->decimal('total', 10, 2);
            
            // Estado de la SUNAT
            $table->string('sunat_status')->default('pending'); // pending, accepted, rejected
            $table->text('sunat_error')->nullable(); // Por si la SUNAT lo rechaza, guardar por qué
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};
