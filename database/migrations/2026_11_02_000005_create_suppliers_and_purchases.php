<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 6 (PLAN_MODA): proveedores y compras. El stock de una compra entra por el kardex.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 2)->default('6');      // 6 = RUC, 1 = DNI
            $table->string('document_number', 15)->nullable()->unique();
            $table->string('name');
            $table->string('contact_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('document_type', 20)->default('factura'); // factura | boleta | guia | sin_comprobante
            $table->string('document_number', 30)->nullable();
            $table->date('issue_date');
            $table->string('status', 12)->default('registrada');     // registrada | anulada
            $table->decimal('total', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['issue_date', 'branch_id']);
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained('purchases')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_cost', 10, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('suppliers');
    }
};
