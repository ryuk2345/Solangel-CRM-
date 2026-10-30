<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('name', 100);
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('branch_id');
        });

        // Backfill: crear Almacén Principal por defecto para la Sucursal 1 si no existe
        $existe = DB::table('warehouses')->where('branch_id', 1)->exists();
        if (!$existe) {
            DB::table('warehouses')->insert([
                'id' => 1,
                'branch_id' => 1,
                'name' => 'Almacén Principal',
                'is_default' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
