<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class Warehouse extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected static function booted()
    {
        static::saving(function ($warehouse) {
            // Regla: No permitir desactivar el almacén por defecto de una tienda
            if ($warehouse->is_default && !$warehouse->is_active) {
                throw ValidationException::withMessages([
                    'is_active' => 'El almacén por defecto de la tienda no se puede desactivar. Primero debes marcar otro almacén como por defecto.',
                ]);
            }

            // Regla: No permitir dejar una tienda sin almacenes activos
            if (!$warehouse->is_active) {
                $otherActiveCount = static::where('branch_id', $warehouse->branch_id)
                    ->where('id', '!=', $warehouse->id ?? 0)
                    ->where('is_active', true)
                    ->count();

                if ($otherActiveCount === 0) {
                    throw ValidationException::withMessages([
                        'is_active' => 'No puedes desactivar el único almacén activo de esta tienda.',
                    ]);
                }
            }

            // Regla: No permitir quitar la marca de por defecto si no hay otro almacén por defecto
            if (!$warehouse->is_default && $warehouse->exists && $warehouse->getOriginal('is_default')) {
                $otherDefaultExists = static::where('branch_id', $warehouse->branch_id)
                    ->where('id', '!=', $warehouse->id)
                    ->where('is_default', true)
                    ->exists();

                if (!$otherDefaultExists) {
                    throw ValidationException::withMessages([
                        'is_default' => 'La tienda no puede quedar sin almacén por defecto. Para cambiarlo, primero marca otro almacén como por defecto.',
                    ]);
                }
            }

            // Regla: Solo un almacén por defecto por tienda: al marcar uno, desmarca los demás en la misma transacción
            if ($warehouse->is_default) {
                static::where('branch_id', $warehouse->branch_id)
                    ->where('id', '!=', $warehouse->id ?? 0)
                    ->update(['is_default' => false]);
            }
        });

        static::deleting(function ($warehouse) {
            // Regla: No permitir eliminar el almacén por defecto
            if ($warehouse->is_default) {
                throw ValidationException::withMessages([
                    'id' => 'No se puede eliminar el almacén por defecto de la tienda. Para cambiarlo, marca primero otro almacén como por defecto.',
                ]);
            }

            // Regla: No permitir dejar una tienda sin almacenes
            $otherWarehousesCount = static::where('branch_id', $warehouse->branch_id)
                ->where('id', '!=', $warehouse->id)
                ->where('is_active', true)
                ->count();

            if ($otherWarehousesCount === 0) {
                throw ValidationException::withMessages([
                    'id' => 'No se puede eliminar el único almacén activo de esta tienda.',
                ]);
            }

            // Regla: No permitir eliminar almacenes con stock
            $stockCount = $warehouse->inventories()->where('stock', '>', 0)->count();
            if ($stockCount > 0) {
                throw ValidationException::withMessages([
                    'id' => "No se puede eliminar un almacén con stock físico ({$stockCount} productos con unidades disponibles). Desactívalo en su lugar.",
                ]);
            }

            // Regla: No permitir eliminar almacenes con ventas
            $salesCount = $warehouse->sales()->count();
            if ($salesCount > 0) {
                throw ValidationException::withMessages([
                    'id' => "No se puede eliminar un almacén con historial de ventas ({$salesCount} ventas registradas). Desactívalo en su lugar.",
                ]);
            }

            // Regla: No permitir eliminar almacenes con traspasos de stock
            $transfersCount = $warehouse->originTransfers()->count() + $warehouse->destinationTransfers()->count();
            if ($transfersCount > 0) {
                throw ValidationException::withMessages([
                    'id' => "No se puede eliminar un almacén con historial de traspasos ({$transfersCount} traspasos asociados). Desactívalo en su lugar.",
                ]);
            }
        });

        // Regla: Al crear un almacén, crear filas de inventario en 0 SOLO para las variantes de productos habilitados en su tienda
        static::created(function ($warehouse) {
            $variantIds = ProductVariant::whereHas('product.branches', function ($q) use ($warehouse) {
                $q->where('branches.id', $warehouse->branch_id);
            })->pluck('id');

            if ($variantIds->isEmpty()) {
                return;
            }

            $now = now();
            $records = [];
            foreach ($variantIds as $variantId) {
                $records[] = [
                    'branch_id' => $warehouse->branch_id,
                    'warehouse_id' => $warehouse->id,
                    'product_variant_id' => $variantId,
                    'stock' => 0,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($records, 500) as $chunk) {
                Inventory::insertOrIgnore($chunk);
            }
        });
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function inventories()
    {
        return $this->hasMany(Inventory::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function originTransfers()
    {
        return $this->hasMany(StockTransfer::class, 'origin_warehouse_id');
    }

    public function destinationTransfers()
    {
        return $this->hasMany(StockTransfer::class, 'destination_warehouse_id');
    }
}
