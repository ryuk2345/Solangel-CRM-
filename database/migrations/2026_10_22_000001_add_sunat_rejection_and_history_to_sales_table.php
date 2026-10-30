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
        Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasColumn('sales', 'sunat_error_code')) {
                $table->string('sunat_error_code', 20)->nullable()->after('sunat_error');
            }
            if (!Schema::hasColumn('sales', 'reemission_history')) {
                $table->json('reemission_history')->nullable()->after('sunat_error_code');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'reemission_history')) {
                $table->dropColumn('reemission_history');
            }
            if (Schema::hasColumn('sales', 'sunat_error_code')) {
                $table->dropColumn('sunat_error_code');
            }
        });
    }
};
