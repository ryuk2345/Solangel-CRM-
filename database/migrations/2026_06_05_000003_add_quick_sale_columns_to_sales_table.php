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
            if (!Schema::hasColumn('sales', 'is_quick_sale')) {
                $table->boolean('is_quick_sale')->default(false)->after('sunat_error');
            }
            if (!Schema::hasColumn('sales', 'is_audited')) {
                $table->boolean('is_audited')->default(false)->after('is_quick_sale');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['is_quick_sale', 'is_audited']);
        });
    }
};
