<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    // Permitir que Filament guarde datos en todas estas columnas
    protected $guarded = [];

    // 🌟 AGREGA ESTO PARA QUE LARAVEL ENTIENDA EL SWITCH BOOLEANO
    protected $casts = [
        'is_service' => 'boolean',
        'is_web_visible' => 'boolean',
    ];

    protected static function booted()
    {
        static::deleting(function ($product) {
            $user = auth()->user();
            $variantIds = $product->variants()->pluck('id');

            // Regla: Si el producto es compartido (más de una tienda), solo super_admin puede eliminarlo
            $branchesCount = $product->branches()->count();
            if ($branchesCount > 1 && $user && !$user->hasRole('super_admin')) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'id' => "El producto '{$product->name}' está compartido entre varias tiendas. Solo un super_admin puede eliminarlo.",
                ]);
            }

            // Regla: No permitir eliminar productos con stock físico > 0
            $totalStock = \App\Models\Inventory::whereIn('product_variant_id', $variantIds)->sum('stock');
            if ($totalStock > 0) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'id' => "No se puede eliminar el producto '{$product->name}' porque tiene stock físico disponible ({$totalStock} unidades en sus almacenes). Ajusta el stock a 0 antes de eliminarlo.",
                ]);
            }

            // Regla: No permitir eliminar productos con ventas
            $salesCount = \App\Models\SaleItem::whereIn('product_variant_id', $variantIds)->count();
            if ($salesCount > 0) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'id' => "No se puede eliminar el producto '{$product->name}' porque tiene historial de ventas ({$salesCount} ventas registradas). Desactívalo o quita su visibilidad en su lugar.",
                ]);
            }

            // Regla: No permitir eliminar productos con traspasos
            $transfersCount = \App\Models\StockTransferItem::whereIn('product_variant_id', $variantIds)->count();
            if ($transfersCount > 0) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'id' => "No se puede eliminar el producto '{$product->name}' porque tiene historial de traspasos ({$transfersCount} traspasos registrados).",
                ]);
            }
        });
    }

    // Relación: Sucursales / Tiendas donde el producto está habilitado en su catálogo
    public function branches()
    {
        return $this->belongsToMany(Branch::class, 'branch_product')->withTimestamps();
    }

    // Relación: Un producto pertenece a una categoría
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class);
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }
}