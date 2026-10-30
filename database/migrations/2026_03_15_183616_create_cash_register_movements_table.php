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
        Schema::create('cash_register_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_register_id')->constrained('cash_registers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users'); // Quién sacó o metió la plata
            $table->enum('type', ['ingreso', 'egreso']); // Para saber si sumó o restó
            $table->decimal('amount', 10, 2); // Cuánto dinero fue
            $table->string('description'); // 🌟 Ej: "Pago a motorizado", "Compra de agua"
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_register_movements');
    }
};
