<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('brand')->nullable()->after('material');
            $table->string('oem_code')->nullable()->after('brand');
            $table->string('manufacturer')->nullable()->after('oem_code');
            $table->string('location')->nullable()->after('manufacturer');
            $table->text('compatibility')->nullable()->after('location');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['brand', 'oem_code', 'manufacturer', 'location', 'compatibility']);
        });
    }
};
