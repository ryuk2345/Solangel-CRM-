<?php

namespace App\Models;

use App\Support\Ean13;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Una combinación vendible: modelo + color + talla. Tiene SKU, código de barras EAN-13,
 * precio minorista y mayorista propios (si están vacíos, hereda los del modelo) y stock por almacén.
 */
class ProductVariant extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'sale_price' => 'decimal:2',
        'wholesale_price' => 'decimal:2',
        'purchase_price' => 'decimal:2',
    ];

    protected static function booted()
    {
        // color_name y size (texto) se guardan como copia del catálogo para tickets, traspasos y SUNAT
        static::saving(function (ProductVariant $variant) {
            if ($variant->color_id && $variant->isDirty('color_id')) {
                $variant->color_name = Color::find($variant->color_id)?->name;
            }
            if ($variant->size_id && $variant->isDirty('size_id')) {
                $variant->size = Size::find($variant->size_id)?->name;
            }
        });

        static::created(function (ProductVariant $variant) {
            if (blank($variant->barcode)) {
                $variant->forceFill(['barcode' => Ean13::forVariant($variant->id)])->saveQuietly();
            }
        });

        static::saved(function ($variant) {
            // Fila de inventario en 0 en los almacenes activos de las tiendas donde el modelo está habilitado
            $branchIds = $variant->product?->branches()->pluck('branches.id') ?? collect();
            if ($branchIds->isEmpty() || $variant->product?->is_service) {
                return;
            }

            $warehouses = Warehouse::whereIn('branch_id', $branchIds)->where('is_active', true)->get();
            foreach ($warehouses as $warehouse) {
                Inventory::firstOrCreate([
                    'warehouse_id' => $warehouse->id,
                    'product_variant_id' => $variant->id,
                ], [
                    'branch_id' => $warehouse->branch_id,
                    'stock' => 0,
                ]);
            }
        });

        static::deleting(function (ProductVariant $variant) {
            $stock = Inventory::where('product_variant_id', $variant->id)->sum('stock');
            if ($stock > 0) {
                throw ValidationException::withMessages([
                    'id' => "No se puede eliminar {$variant->display_name}: tiene {$stock} unidades en stock.",
                ]);
            }
            if (SaleItem::where('product_variant_id', $variant->id)->exists()
                || StockTransferItem::where('product_variant_id', $variant->id)->exists()) {
                throw ValidationException::withMessages([
                    'id' => "No se puede eliminar {$variant->display_name}: tiene movimientos registrados. Desactívala en su lugar.",
                ]);
            }
            Inventory::where('product_variant_id', $variant->id)->where('stock', 0)->delete();
        });
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function color()
    {
        return $this->belongsTo(Color::class);
    }

    /** Talla del catálogo (el campo de texto `size` guarda su nombre). */
    public function sizeOption()
    {
        return $this->belongsTo(Size::class, 'size_id');
    }

    public function inventories()
    {
        return $this->hasMany(Inventory::class);
    }

    /** Precio minorista vigente: el de la variante, o el del modelo. */
    public function getRetailPriceAttribute(): float
    {
        return (float) ($this->attributes['sale_price'] ?? null ?: $this->product?->sale_price ?? 0);
    }

    /** Precio mayorista vigente: el de la variante, o el del modelo (o el minorista si no hay mayorista). */
    public function getEffectiveWholesalePriceAttribute(): float
    {
        $price = $this->attributes['wholesale_price'] ?? null ?: $this->product?->wholesale_price;

        return (float) ($price ?: $this->retail_price);
    }

    /** Costo vigente: el de la variante, o el del modelo. */
    public function getCostPriceAttribute(): float
    {
        return (float) ($this->attributes['purchase_price'] ?? null ?: $this->product?->purchase_price ?? 0);
    }

    /** Nombre para tickets y SUNAT: "Polo básico pima – Negro – M". */
    public function getDisplayNameAttribute(): string
    {
        $parts = [$this->product?->name];
        $color = $this->color_name;
        $size = $this->attributes['size'] ?? null;

        if (filled($color) && $color !== 'Único') {
            $parts[] = $color;
        }
        if (filled($size) && $size !== 'Único') {
            $parts[] = $size;
        }

        return implode(' – ', array_filter($parts));
    }

    public function stockIn(?int $warehouseId): int
    {
        return (int) Inventory::where('product_variant_id', $this->id)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->sum('stock');
    }
}
