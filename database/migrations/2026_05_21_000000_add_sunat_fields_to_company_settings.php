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
        Schema::table('company_settings', function (Blueprint $table) {
            $table->string('sol_user')->nullable();
            $table->string('sol_pass')->nullable();
            $table->string('cert_path')->nullable();
            $table->string('cert_password')->nullable();
            $table->string('client_id')->nullable();
            $table->string('client_secret')->nullable();
            $table->boolean('sunat_sandbox')->default(true);
            $table->string('ubigueo')->default('150101');
            $table->string('department')->default('LIMA');
            $table->string('province')->default('LIMA');
            $table->string('district')->default('LIMA');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn([
                'sol_user',
                'sol_pass',
                'cert_path',
                'cert_password',
                'client_id',
                'client_secret',
                'sunat_sandbox',
                'ubigueo',
                'department',
                'province',
                'district'
            ]);
        });
    }
};
