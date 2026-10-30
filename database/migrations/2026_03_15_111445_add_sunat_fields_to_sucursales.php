<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cambia 'sucursales' por el nombre de tu tabla si es diferente
        Schema::table('branches', function (Blueprint $table) {
            $table->string('sunat_code', 4)->default('0000')->after('address');
            $table->string('ubigeo', 6)->nullable()->after('sunat_code');
            $table->string('department')->nullable()->after('ubigeo');
            $table->string('province')->nullable()->after('department');
            $table->string('district')->nullable()->after('province');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['sunat_code', 'ubigeo', 'department', 'province', 'district']);
        });
    }
};