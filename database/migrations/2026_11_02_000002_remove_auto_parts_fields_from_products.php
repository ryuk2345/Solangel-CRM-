<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Etapa 1 — Limpieza de repuestos (PLAN_MODA).
 * Quita los campos exclusivos de autopartes, deja la tienda web apagada por defecto
 * y crea una bitácora del sistema para registrar cambios importantes.
 */
return new class extends Migration
{
    private array $autoPartsColumns = ['oem_code', 'manufacturer', 'compatibility', 'brand'];

    public function up(): void
    {
        $existing = array_values(array_filter($this->autoPartsColumns, fn ($c) => Schema::hasColumn('products', $c)));
        if ($existing) {
            Schema::table('products', function (Blueprint $table) use ($existing) {
                $table->dropColumn($existing);
            });
        }

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_web_visible')->default(false)->change();
        });

        if (!Schema::hasTable('activity_logs')) {
            Schema::create('activity_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('action', 60);          // ej: feature_removed, price_override, wholesale_approved
                $table->string('description');
                $table->nullableMorphs('subject');
                $table->json('properties')->nullable();
                $table->timestamps();
                $table->index('action');
            });
        }

        DB::table('activity_logs')->insert([
            'action'      => 'feature_removed',
            'description' => 'Se retiró la función "Venta rápida" (ventas sin detalle de productos). Las ventas rápidas existentes quedan visibles solo para consulta.',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_web_visible')->default(true)->change();
        });

        Schema::table('products', function (Blueprint $table) {
            foreach (['brand', 'oem_code', 'manufacturer', 'location', 'compatibility'] as $col) {
                if ($col === 'location') {
                    continue; // location se conserva (ubicación en almacén)
                }
                if (!Schema::hasColumn('products', $col)) {
                    $col === 'compatibility'
                        ? $table->text($col)->nullable()
                        : $table->string($col)->nullable();
                }
            }
        });
    }
};
