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
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'oem_code')) {
                $table->string('oem_code')->nullable()->after('name');
            }
            if (!Schema::hasColumn('products', 'manufacturer')) {
                $table->string('manufacturer')->nullable()->after('oem_code');
            }
            if (!Schema::hasColumn('products', 'compatibility')) {
                $table->text('compatibility')->nullable()->after('manufacturer');
            }
            if (!Schema::hasColumn('products', 'location')) {
                $table->string('location')->nullable()->after('compatibility');
            }
            if (!Schema::hasColumn('products', 'brand')) {
                $table->string('brand')->nullable()->after('brand_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['oem_code', 'manufacturer', 'compatibility', 'location', 'brand']);
        });
    }
};
