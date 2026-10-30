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
        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();
            $table->string('ruc')->default('20000000001');
            $table->string('name')->default('BOUTIQUE SAC');
            $table->string('address_principal')->nullable(); // Sede Matriz
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('logo')->nullable(); // Guardaremos la ruta de la imagen
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};
