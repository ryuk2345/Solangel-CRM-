<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 4)->default(0)->after('quantity');
            $table->decimal('total_cost', 14, 2)->default(0)->after('unit_cost');
            $table->decimal('balance_unit_cost', 12, 4)->default(0)->after('balance_after');
            $table->decimal('balance_total_cost', 14, 2)->default(0)->after('balance_unit_cost');
            $table->string('guide_document', 30)->nullable()->after('note');
            $table->string('payment_document', 30)->nullable()->after('guide_document');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn([
                'unit_cost',
                'total_cost',
                'balance_unit_cost',
                'balance_total_cost',
                'guide_document',
                'payment_document',
            ]);
        });
    }
};
