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
        Schema::create('series', function (Blueprint $table) {
            $table->id();
            $table->string('document_type'); // '01' para Factura, '03' para Boleta
            $table->string('serie'); // Ej: 'F001' o 'B001'
            $table->integer('current_number')->default(0); // El último número usado
            $table->boolean('is_active')->default(true); // Para apagar o prender series
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('series');
    }
};
