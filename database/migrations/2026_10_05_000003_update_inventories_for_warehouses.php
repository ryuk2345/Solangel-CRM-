<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Agregar columna warehouse_id nullable e índice simple en branch_id
        Schema::table('inventories', function (Blueprint $table) {
            $table->unsignedBigInteger('warehouse_id')->nullable()->after('branch_id');
            $table->index('branch_id', 'inventories_branch_id_idx');
        });

        // 2. Backfill: asociar inventarios existentes al almacén por defecto de su sucursal
        DB::statement("
            UPDATE inventories i
            JOIN warehouses w ON w.branch_id = i.branch_id AND w.is_default = 1
            SET i.warehouse_id = w.id
            WHERE i.warehouse_id IS NULL
        ");

        // Fallback defensivo limitado estrictamente a branch_id = 1
        DB::table('inventories')
            ->where('branch_id', 1)
            ->whereNull('warehouse_id')
            ->update(['warehouse_id' => 1]);

        // 3. Reemplazar índice único y agregar llave foránea con RESTRICT
        Schema::table('inventories', function (Blueprint $table) {
            $table->dropUnique('inventories_branch_id_product_variant_id_unique');
            $table->unique(['warehouse_id', 'product_variant_id'], 'inventories_warehouse_variant_unique');
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            $table->dropForeign(['warehouse_id']);
            $table->dropUnique('inventories_warehouse_variant_unique');
            $table->unique(['branch_id', 'product_variant_id'], 'inventories_branch_id_product_variant_id_unique');
            $table->dropIndex('inventories_branch_id_idx');
            $table->dropColumn('warehouse_id');
        });
    }
};
