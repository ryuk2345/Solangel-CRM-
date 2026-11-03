<?php

namespace App\Support;

use App\Models\Inventory;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Services\PricingService;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Illuminate\Support\Facades\Auth;

/**
 * Ayudante del formulario de venta y de cotización: busca prendas con stock,
 * arma las etiquetas y recalcula los precios con PricingService cada vez que cambia algo.
 *
 * En el formulario los precios solo se muestran: al guardar, CreateSale los vuelve a calcular.
 */
class SaleCart
{
    /** Almacén de la venta: el elegido o el predeterminado de la tienda. */
    public static function warehouseFor(?int $branchId, ?int $warehouseId): ?int
    {
        if ($warehouseId) {
            return (int) $warehouseId;
        }
        if (!$branchId) {
            return null;
        }

        return Warehouse::where('branch_id', $branchId)->where('is_default', true)->value('id')
            ?? Warehouse::where('branch_id', $branchId)->where('is_active', true)->value('id');
    }

    /**
     * Opciones del buscador: prendas con stock en el almacén (busca por código de barras, SKU, modelo o color)
     * y el delivery. Las prendas sin stock no se ofrecen (no se vende sin stock).
     */
    public static function options(string $search, ?int $branchId, ?int $warehouseId, bool $includeOutOfStock = false): array
    {
        $warehouseId = self::warehouseFor($branchId, $warehouseId);
        if (!$branchId || !$warehouseId) {
            return [];
        }

        $search = trim($search);
        $options = [];

        $query = Inventory::query()
            ->with(['productVariant.product'])
            ->where('warehouse_id', $warehouseId)
            ->when(!$includeOutOfStock, fn ($q) => $q->where('stock', '>', 0))
            ->whereHas('productVariant.product', fn ($p) => $p->where('is_service', false)
                ->whereHas('branches', fn ($b) => $b->where('branches.id', $branchId)));

        if ($search !== '') {
            $query->whereHas('productVariant', function ($q) use ($search) {
                $q->where('barcode', $search)
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('color_name', 'like', "%{$search}%")
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%"));
            });
        }

        foreach ($query->limit(50)->get() as $inventory) {
            $variant = $inventory->productVariant;
            if ($variant?->product) {
                $options[$variant->id] = self::label($variant, (int) $inventory->stock);
            }
        }

        $services = ProductVariant::with('product')
            ->whereHas('product', fn ($p) => $p->where('is_service', true)
                ->when($search !== '', fn ($q) => $q->where('name', 'like', "%{$search}%")))
            ->limit(5)->get();

        foreach ($services as $service) {
            $options[$service->id] = '🚚 ' . $service->product->name . ' (servicio)';
        }

        return $options;
    }

    /** Busca una prenda por código de barras o SKU exacto (lectora). */
    public static function findByCode(string $code): ?ProductVariant
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        return ProductVariant::with('product')
            ->where('barcode', $code)
            ->orWhere('sku', $code)
            ->first();
    }

    public static function label(ProductVariant $variant, ?int $stock = null): string
    {
        if ($variant->product?->is_service) {
            return '🚚 ' . $variant->product->name . ' (servicio)';
        }

        $prices = 'Min. S/ ' . number_format($variant->retail_price, 2)
            . ' · May. S/ ' . number_format($variant->effective_wholesale_price, 2);
        $stockText = $stock === null ? '' : " · Stock: {$stock}";

        return "[{$variant->sku}] {$variant->display_name} · {$prices}{$stockText}";
    }

    public static function optionLabel($value, ?int $warehouseId = null): string
    {
        $variant = ProductVariant::with('product')->find($value);
        if (!$variant) {
            return '';
        }

        return self::label($variant, $variant->product?->is_service ? null : $variant->stockIn($warehouseId));
    }

    /**
     * Recalcula todas las líneas con la regla de precios.
     *
     * @param  string  $prefix  '' desde la cabecera, '../../' desde una línea del detalle.
     */
    public static function recalc(Get $get, Set $set, string $prefix = ''): void
    {
        $items = $get($prefix . 'items') ?? [];
        if (!is_array($items)) {
            return;
        }

        $result = PricingService::apply(
            $items,
            $get($prefix . 'customer_id') ? (int) $get($prefix . 'customer_id') : null,
            $get($prefix . 'branch_id') ? (int) $get($prefix . 'branch_id') : null,
            $get($prefix . 'wholesale_request_id') ? (int) $get($prefix . 'wholesale_request_id') : null,
            Auth::user(),
        );

        $set($prefix . 'items', $result['lines']);
        $set($prefix . 'total', $result['total']);
    }

    /** Texto del indicador minorista/mayorista. */
    public static function summary(Get $get): string
    {
        $result = PricingService::apply(
            $get('items') ?? [],
            $get('customer_id') ? (int) $get('customer_id') : null,
            $get('branch_id') ? (int) $get('branch_id') : null,
            $get('wholesale_request_id') ? (int) $get('wholesale_request_id') : null,
            Auth::user(),
        );

        return PricingService::summary($result);
    }

    /** Cuántas prendas (sin servicios) hay en el detalle. */
    public static function garments(array $items): int
    {
        $ids = collect($items)->pluck('product_variant_id')->filter()->unique();
        if ($ids->isEmpty()) {
            return 0;
        }
        $services = ProductVariant::whereIn('id', $ids)
            ->whereHas('product', fn ($p) => $p->where('is_service', true))
            ->pluck('id')->all();

        return (int) collect($items)
            ->reject(fn ($i) => in_array((int) ($i['product_variant_id'] ?? 0), $services, true))
            ->sum(fn ($i) => (int) ($i['quantity'] ?? 0));
    }

    /** ¿Alguna prenda del detalle permite delivery? (decisión 4) */
    public static function allowsDelivery(array $items): bool
    {
        $ids = collect($items)->pluck('product_variant_id')->filter()->unique();

        return $ids->isNotEmpty() && ProductVariant::whereIn('id', $ids)
            ->whereHas('product', fn ($p) => $p->where('is_service', false)->where('allows_delivery', true))
            ->exists();
    }
}
