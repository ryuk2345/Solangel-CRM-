<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('warehouse_id')
                ->nullable()
                ->after('branch_id')
                ->constrained('warehouses')
                ->restrictOnDelete();
        });

        // Backfill: asociar ventas existentes al almacén por defecto de su sucursal
        DB::statement("
            UPDATE sales s
            JOIN warehouses w ON w.branch_id = s.branch_id AND w.is_default = 1
            SET s.warehouse_id = w.id
            WHERE s.warehouse_id IS NULL
        ");

        // Fallback defensivo limitado estrictamente a branch_id = 1
        DB::table('sales')
            ->where('branch_id', 1)
            ->whereNull('warehouse_id')
            ->update(['warehouse_id' => 1]);
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropForeign(['warehouse_id']);
            $table->dropColumn('warehouse_id');
        });
    }
};
