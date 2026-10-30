<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('branch_product')) {
            Schema::create('branch_product', function (Blueprint $table) {
                $table->id();
                $table->foreignId('branch_id')->constrained('branches')->onDelete('restrict');
                $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
                $table->timestamps();

                $table->unique(['branch_id', 'product_id'], 'branch_product_branch_id_product_id_unique');
            });
        }

        // Llenado aditivo: todos los productos actuales quedan habilitados en la Sucursal Principal (id: 1)
        DB::statement("
            INSERT INTO `branch_product` (`branch_id`, `product_id`, `created_at`, `updated_at`)
            SELECT 1, p.id, NOW(), NOW()
            FROM `products` p
            WHERE NOT EXISTS (
                SELECT 1 FROM `branch_product` bp WHERE bp.branch_id = 1 AND bp.product_id = p.id
            )
        ");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('branch_product');
    }
};
