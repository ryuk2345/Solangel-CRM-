<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Crear tablas de ubigeo si no existen para evitar error 500
        if (!Schema::hasTable('ubi_regiones')) {
            Schema::create('ubi_regiones', function (Blueprint $table) {
                $table->char('id', 2)->primary();
                $table->string('nombre');
            });

            DB::table('ubi_regiones')->insert([
                ['id' => '01', 'nombre' => 'AMAZONAS'],
                ['id' => '02', 'nombre' => 'ANCASH'],
                ['id' => '03', 'nombre' => 'APURIMAC'],
                ['id' => '04', 'nombre' => 'AREQUIPA'],
                ['id' => '05', 'nombre' => 'AYACUCHO'],
                ['id' => '06', 'nombre' => 'CAJAMARCA'],
                ['id' => '07', 'nombre' => 'CALLAO'],
                ['id' => '08', 'nombre' => 'CUSCO'],
                ['id' => '09', 'nombre' => 'HUANCAVELICA'],
                ['id' => '10', 'nombre' => 'HUANUCO'],
                ['id' => '11', 'nombre' => 'ICA'],
                ['id' => '12', 'nombre' => 'JUNIN'],
                ['id' => '13', 'nombre' => 'LA LIBERTAD'],
                ['id' => '14', 'nombre' => 'LAMBAYEQUE'],
                ['id' => '15', 'nombre' => 'LIMA'],
                ['id' => '16', 'nombre' => 'LORETO'],
                ['id' => '17', 'nombre' => 'MADRE DE DIOS'],
                ['id' => '18', 'nombre' => 'MOQUEGUA'],
                ['id' => '19', 'nombre' => 'PASCO'],
                ['id' => '20', 'nombre' => 'PIURA'],
                ['id' => '21', 'nombre' => 'PUNO'],
                ['id' => '22', 'nombre' => 'SAN MARTIN'],
                ['id' => '23', 'nombre' => 'TACNA'],
                ['id' => '24', 'nombre' => 'TUMBES'],
                ['id' => '25', 'nombre' => 'UCAYALI'],
            ]);
        }

        if (!Schema::hasTable('ubi_provincias')) {
            Schema::create('ubi_provincias', function (Blueprint $table) {
                $table->char('id', 4)->primary();
                $table->string('nombre');
                $table->char('region_id', 2);
            });
        }

        if (!Schema::hasTable('ubi_distritos')) {
            Schema::create('ubi_distritos', function (Blueprint $table) {
                $table->char('id', 6)->primary();
                $table->string('nombre');
                $table->char('provincia_id', 4);
                $table->char('region_id', 2)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ubi_distritos');
        Schema::dropIfExists('ubi_provincias');
        Schema::dropIfExists('ubi_regiones');
    }
};
