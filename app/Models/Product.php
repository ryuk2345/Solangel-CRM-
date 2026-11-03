<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Modelo de prenda (por ejemplo "Polo básico pima"). Cada combinación de color y talla
 * es una ProductVariant con su propio SKU, código de barras y stock.
 */
class Product extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'is_service' => 'boolean',
        'is_web_visible' => 'boolean',
        'allows_delivery' => 'boolean',
        'sale_price' => 'decimal:2',
        'wholesale_price' => 'decimal:2',
        'purchase_price' => 'decimal:2',
    ];

    protected static function booted()
    {
        // Código del modelo (base del SKU). Si no se escribe, se genera: M00012
        static::saving(function (Product $product) {
            if (filled($product->code)) {
                $product->code = strtoupper(preg_replace('/\s+/', '-', trim($product->code)));
            }
        });

        static::created(function (Product $product) {
            if (blank($product->code) && !$product->is_service) {
                $product->forceFill(['code' => 'M' . str_pad((string) $product->id, 5, '0', STR_PAD_LEFT)])->saveQuietly();
            }
        });

        static::deleting(function ($product) {
            $user = auth()->user();
            $variantIds = $product->variants()->pluck('id');

            if ($product->branches()->count() > 1 && $user && !$user->seesAllBranches()) {
                throw ValidationException::withMessages([
                    'id' => "El producto '{$product->name}' está compartido entre varias tiendas. Solo el administrador puede eliminarlo.",
                ]);
            }

            $totalStock = Inventory::whereIn('product_variant_id', $variantIds)->sum('stock');
            if ($totalStock > 0) {
                throw ValidationException::withMessages([
                    'id' => "No se puede eliminar '{$product->name}': tiene {$totalStock} unidades en stock. Ajusta el stock a 0 antes de eliminarlo.",
                ]);
            }

            $salesCount = SaleItem::whereIn('product_variant_id', $variantIds)->count();
            if ($salesCount > 0) {
                throw ValidationException::withMessages([
                    'id' => "No se puede eliminar '{$product->name}': tiene ventas registradas. Desactiva sus variantes en su lugar.",
                ]);
            }

            if (StockTransferItem::whereIn('product_variant_id', $variantIds)->exists()) {
                throw ValidationException::withMessages([
                    'id' => "No se puede eliminar '{$product->name}': tiene traspasos registrados.",
                ]);
            }
        });
    }

    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'branch_product')->withTimestamps();
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function season()
    {
        return $this->belongsTo(Season::class);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class);
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }

    /** Umbral de stock bajo de este modelo, o el general de la empresa (20 por defecto). */
    public function lowStockThreshold(): int
    {
        return (int) ($this->low_stock_threshold ?? CompanySetting::current()?->low_stock_threshold ?? 20);
    }
}
