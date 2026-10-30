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
        // Le decimos: "Si la tabla sales NO tiene la columna sunat_status, entonces créala"
        if (!Schema::hasColumn('sales', 'sunat_status')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->string('sunat_status')->default('pendiente');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            //
        });
    }
};
