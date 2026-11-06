<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapas 4 y 5 (PLAN_MODA): regla de precios minorista/mayorista con aprobación del
 * administrador, y control de stock con kardex (sin stock negativo).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('customer_type', 12)->default('minorista')->after('document_number'); // minorista | mayorista
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('approval_pin')->nullable()->after('password'); // PIN cifrado para aprobar precios
        });

        Schema::create('wholesale_price_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 12)->default('pendiente'); // pendiente | aprobada | rechazada | usada
            $table->json('items')->nullable();                   // prendas de la venta en el momento de pedir
            $table->string('note')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('sale_id')->nullable()->constrained('sales')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'branch_id']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('wholesale_request_id')->nullable()->after('warehouse_id')
                ->constrained('wholesale_price_requests')->nullOnDelete();
            $table->string('price_mode', 10)->nullable()->after('wholesale_request_id'); // minorista | mayorista
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('list_price', 10, 2)->nullable()->after('price');     // precio de lista en ese momento
            $table->string('price_type', 10)->nullable()->after('list_price');    // retail | wholesale | service
            $table->string('price_reason', 12)->nullable()->after('price_type');  // quantity | authorized | manual
            $table->foreignId('price_set_by')->nullable()->after('price_reason')->constrained('users')->nullOnDelete();
        });

        Schema::table('quotation_items', function (Blueprint $table) {
            $table->string('price_type', 10)->nullable()->after('price');
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->string('type', 20);           // sale, sale_void, purchase, adjustment, transfer_out, transfer_in, exchange_in, ...
            $table->integer('quantity');          // con signo: + entra, - sale
            $table->integer('balance_after');
            $table->nullableMorphs('reference');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['product_variant_id', 'warehouse_id', 'created_at'], 'stock_movements_variant_wh_date');
        });

        // Notificaciones del panel (aviso al administrador de aprobaciones pendientes)
        if (!Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('stock_movements');

        Schema::table('quotation_items', fn (Blueprint $t) => $t->dropColumn('price_type'));

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('price_set_by');
            $table->dropColumn(['list_price', 'price_type', 'price_reason']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wholesale_request_id');
            $table->dropColumn('price_mode');
        });

        Schema::dropIfExists('wholesale_price_requests');

        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('approval_pin'));
        Schema::table('customers', fn (Blueprint $t) => $t->dropColumn('customer_type'));
    }
};
