<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 8 (PLAN_MODA, decisión 7): cambios de talla o color y devoluciones.
 * Cada cambio genera una nota de crédito PARCIAL (solo por las prendas devueltas)
 * y la prenda nueva se cobra en una venta nueva, pagada con ese saldo a favor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 12)->default('cambio');            // cambio | devolucion
            $table->string('reason');                                  // motivo del cliente
            $table->decimal('total', 10, 2);                           // valor devuelto (saldo a favor)
            $table->string('credit_note_serie', 10)->nullable();
            $table->integer('credit_note_correlative')->nullable();
            $table->string('sunat_status', 12)->default('pendiente');  // pendiente | aceptado | rechazado | interna
            $table->text('sunat_error')->nullable();
            $table->foreignId('new_sale_id')->nullable()->constrained('sales')->nullOnDelete(); // venta del cambio
            $table->decimal('refunded_cash', 10, 2)->default(0);      // dinero devuelto (solo devoluciones)
            $table->timestamps();
            $table->index(['sale_id', 'type']);
        });

        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_return_id')->constrained('sale_returns')->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained('sale_items')->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->string('product_name');
            $table->unsignedInteger('quantity');
            $table->decimal('price', 10, 2);
            $table->decimal('total', 10, 2);
            $table->timestamps();
        });

        Schema::table('sales', function (Blueprint $table) {
            // La venta nueva de un cambio apunta a la devolución que la origina
            $table->foreignId('exchange_return_id')->nullable()->after('wholesale_request_id')
                ->constrained('sale_returns')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales', fn (Blueprint $t) => $t->dropConstrainedForeignId('exchange_return_id'));
        Schema::dropIfExists('sale_return_items');
        Schema::dropIfExists('sale_returns');
    }
};
