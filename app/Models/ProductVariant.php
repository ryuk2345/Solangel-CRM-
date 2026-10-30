<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    use HasFactory;

    protected $guarded = []; // Nuestra línea mágica de seguridad

    protected static function booted()
    {
        static::saved(function ($variant) {
            // Asegurar que exista inventario SOLO en los almacenes activos de las tiendas donde el producto está habilitado
            $branchIds = $variant->product?->branches()->pluck('branches.id') ?? collect();
            if ($branchIds->isEmpty()) {
                return;
            }

            $warehouses = \App\Models\Warehouse::whereIn('branch_id', $branchIds)
                ->where('is_active', true)
                ->get();

            foreach ($warehouses as $warehouse) {
                \App\Models\Inventory::firstOrCreate([
                    'warehouse_id' => $warehouse->id,
                    'product_variant_id' => $variant->id,
                ], [
                    'branch_id' => $warehouse->branch_id,
                    'stock' => 0,
                ]);
            }
        });
    }

    // Pertenece a un producto principal
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // Tiene muchos registros de inventario (en diferentes sucursales)
    public function inventories()
    {
        return $this->hasMany(Inventory::class);
    }
}