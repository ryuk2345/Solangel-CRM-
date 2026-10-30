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
        Schema::create('cash_registers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users'); // El cajero que abre la caja
            $table->foreignId('branch_id')->nullable()->constrained('branches'); // La sucursal
            $table->enum('status', ['abierta', 'cerrada'])->default('abierta');
            
            $table->dateTime('opened_at');
            $table->dateTime('closed_at')->nullable();
            
            // Los montos matemáticos
            $table->decimal('initial_amount', 10, 2)->default(0); // Sencillo de apertura
            $table->decimal('cash_sales', 10, 2)->default(0); // Total de ventas en efectivo
            $table->decimal('cash_incomes', 10, 2)->default(0); // Otros ingresos manuales
            $table->decimal('cash_expenses', 10, 2)->default(0); // 🌟 TUS EGRESOS (Pagos, compras)
            
            $table->decimal('expected_amount', 10, 2)->default(0); // Lo que DEBERÍA haber
            $table->decimal('declared_amount', 10, 2)->nullable(); // Lo que contó Brisza físicamente
            $table->decimal('difference', 10, 2)->nullable(); // Sobrante o Faltante
            
            $table->text('notes')->nullable(); // Observaciones del cierre
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_registers');
    }
};
