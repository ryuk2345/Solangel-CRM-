<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Verificamos si Series YA tiene branch_id antes de crearlo
        if (!Schema::hasColumn('series', 'branch_id')) {
            Schema::table('series', function (Blueprint $table) {
                $table->unsignedBigInteger('branch_id')->nullable()->after('id');
            });
        }

        // 2. Verificamos si Inventories YA tiene branch_id antes de crearlo
        if (!Schema::hasColumn('inventories', 'branch_id')) {
            Schema::table('inventories', function (Blueprint $table) {
                $table->unsignedBigInteger('branch_id')->nullable()->after('id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('series', 'branch_id')) {
            Schema::table('series', function (Blueprint $table) {
                $table->dropColumn('branch_id');
            });
        }
        
        if (Schema::hasColumn('inventories', 'branch_id')) {
            Schema::table('inventories', function (Blueprint $table) {
                $table->dropColumn('branch_id');
            });
        }
    }
};