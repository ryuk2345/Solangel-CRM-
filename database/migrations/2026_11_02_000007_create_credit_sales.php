<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 8b (decisión 8): ventas a crédito para clientes mayoristas.
 * Límite y días de crédito por cliente, saldo pendiente por venta y abonos que entran a caja.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('credit_enabled')->default(false)->after('customer_type');
            $table->decimal('credit_limit', 12, 2)->nullable()->after('credit_enabled');
            $table->unsignedSmallInteger('credit_days')->default(30)->after('credit_limit');
        });

        Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasColumn('sales', 'due_date')) {
                $table->date('due_date')->nullable()->after('payment_condition');
            }
            $table->decimal('credit_balance', 12, 2)->default(0)->after('total'); // saldo pendiente de cobro
            $table->index(['customer_id', 'credit_balance']);
        });

        Schema::create('customer_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('sale_id')->constrained('sales')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('cash_register_id')->nullable()->constrained('cash_registers')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payment_method', 20);
            $table->decimal('amount', 12, 2);
            $table->string('reference')->nullable(); // n.º de operación de Yape, transferencia, etc.
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_payments');

        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex(['customer_id', 'credit_balance']);
            $table->dropColumn('credit_balance');
            $table->dropColumn('due_date');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['credit_enabled', 'credit_limit', 'credit_days']);
        });
    }
};
