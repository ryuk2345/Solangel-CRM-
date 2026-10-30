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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('document_type'); // Ej: '1' para DNI, '6' para RUC
            $table->string('document_number')->unique(); // El número de DNI o RUC
            $table->string('name'); // Nombre o Razón Social
            $table->string('address')->nullable(); // Obligatorio para facturas
            $table->string('email')->nullable(); // Para enviarle el XML/PDF
            $table->string('phone')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
