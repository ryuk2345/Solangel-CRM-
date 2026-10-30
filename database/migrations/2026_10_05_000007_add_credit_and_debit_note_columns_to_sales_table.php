<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->string('credit_note_serie', 10)->nullable()->after('quotation_id');
            $table->integer('credit_note_correlative')->nullable()->after('credit_note_serie');
            $table->string('debit_note_serie', 10)->nullable()->after('credit_note_correlative');
            $table->integer('debit_note_correlative')->nullable()->after('debit_note_serie');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn([
                'credit_note_serie',
                'credit_note_correlative',
                'debit_note_serie',
                'debit_note_correlative',
            ]);
        });
    }
};
