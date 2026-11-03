<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class Inventory extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function booted()
    {
        static::deleting(function ($inventory) {
            if ($inventory->stock > 0) {
                throw ValidationException::withMessages([
                    'stock' => "No se puede eliminar un registro de inventario con stock positivo ({$inventory->stock} unidades). Ajusta el stock a 0 antes de eliminarlo.",
                ]);
            }
        });
    }

    // Este inventario pertenece a una variante específica (ej. Blusa Roja M)
    public function productVariant()
    {
        return $this->belongsTo(ProductVariant::class);
    }

    // Y está ubicado en una sucursal específica
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    // Y en un almacén específico de esa sucursal
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Stock bajo por talla y color en cada almacén (decisión 10): stock <= umbral del modelo
     * o, si el modelo no tiene uno propio, el general de la empresa (20 por defecto).
     */
    public function scopeLowStock($query)
    {
        $global = CompanySetting::lowStockThreshold();

        return $query
            ->join('product_variants as lsv', 'lsv.id', '=', 'inventories.product_variant_id')
            ->join('products as lsp', 'lsp.id', '=', 'lsv.product_id')
            ->where('lsp.is_service', false)
            ->whereRaw('inventories.stock <= COALESCE(lsp.low_stock_threshold, ?)', [$global])
            ->select('inventories.*');
    }

    public function getLowStockThresholdAttribute(): int
    {
        return $this->productVariant?->product?->lowStockThreshold() ?? CompanySetting::lowStockThreshold();
    }

    public function getIsLowStockAttribute(): bool
    {
        return (int) $this->stock <= $this->low_stock_threshold;
    }
}