<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\Size;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Compras (Etapa 6): registrar ingresa el stock por el kardex; anular lo saca
 * (solo si las prendas siguen en el almacén).
 */
class PurchaseService
{
    /**
     * Expande una compra por curva en líneas por variante.
     * Ejemplo: 2 paquetes de Negro con curva S:1, M:2, L:2, XL:1 → 2 S, 4 M, 4 L, 2 XL.
     * Si una combinación color × talla no existe, se crea la variante.
     *
     * @param  array<int,int>  $packsByColor  color_id => paquetes
     * @param  array<int,int>  $unitsBySize   size_id => unidades por paquete
     * @return array<int, array{product_variant_id:int, quantity:int, unit_cost:float}>
     */
    public static function expandCurve(Product $product, array $packsByColor, array $unitsBySize, float $unitCost): array
    {
        $packsByColor = array_filter(array_map('intval', $packsByColor), fn ($p) => $p > 0);
        $unitsBySize = array_filter(array_map('intval', $unitsBySize), fn ($u) => $u > 0);

        if (empty($packsByColor) || empty($unitsBySize)) {
            throw ValidationException::withMessages(['curve' => 'Indica al menos un color con paquetes y una talla con unidades.']);
        }

        VariantGenerator::generate($product, array_keys($packsByColor), array_keys($unitsBySize));

        $variants = ProductVariant::where('product_id', $product->id)
            ->whereIn('color_id', array_keys($packsByColor))
            ->whereIn('size_id', array_keys($unitsBySize))
            ->get();

        $sizeOrder = Size::whereIn('id', array_keys($unitsBySize))->pluck('sort_order', 'id');

        return $variants
            ->sortBy(fn ($v) => [$v->color_id, $sizeOrder[$v->size_id] ?? 0])
            ->map(fn (ProductVariant $v) => [
                'product_variant_id' => $v->id,
                'quantity' => $packsByColor[$v->color_id] * $unitsBySize[$v->size_id],
                'unit_cost' => round($unitCost, 2),
            ])
            ->values()->all();
    }

    /**
     * @param  array  $header  supplier_id, warehouse_id, document_type, document_number, issue_date, notes
     * @param  array<int, array{product_variant_id:int, quantity:int, unit_cost:float}>  $lines
     */
    public static function register(array $header, array $lines): Purchase
    {
        $lines = collect($lines)
            ->filter(fn ($l) => !empty($l['product_variant_id']) && (int) ($l['quantity'] ?? 0) > 0)
            ->groupBy(fn ($l) => (int) $l['product_variant_id'] . '|' . round((float) ($l['unit_cost'] ?? 0), 2))
            ->map(fn ($group) => [
                'product_variant_id' => (int) $group->first()['product_variant_id'],
                'quantity' => (int) $group->sum('quantity'),
                'unit_cost' => round((float) ($group->first()['unit_cost'] ?? 0), 2),
            ])
            ->values();

        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['items' => 'La compra no tiene prendas.']);
        }

        $services = ProductVariant::whereIn('id', $lines->pluck('product_variant_id'))
            ->whereHas('product', fn ($p) => $p->where('is_service', true))->exists();
        if ($services) {
            throw ValidationException::withMessages(['items' => 'Los servicios (delivery) no se compran ni tienen stock.']);
        }

        $warehouse = Warehouse::findOrFail($header['warehouse_id']);

        return DB::transaction(function () use ($header, $lines, $warehouse) {
            $purchase = Purchase::create([
                'supplier_id' => $header['supplier_id'],
                'branch_id' => $warehouse->branch_id,
                'warehouse_id' => $warehouse->id,
                'user_id' => Auth::id(),
                'document_type' => $header['document_type'] ?? 'factura',
                'document_number' => $header['document_number'] ?? null,
                'issue_date' => $header['issue_date'] ?? now()->toDateString(),
                'notes' => $header['notes'] ?? null,
                'total' => round($lines->sum(fn ($l) => $l['quantity'] * $l['unit_cost']), 2),
            ]);

            foreach ($lines as $line) {
                $purchase->items()->create([
                    'product_variant_id' => $line['product_variant_id'],
                    'quantity' => $line['quantity'],
                    'unit_cost' => $line['unit_cost'],
                    'total' => round($line['quantity'] * $line['unit_cost'], 2),
                ]);

                StockService::move(
                    $line['product_variant_id'],
                    $warehouse->id,
                    $line['quantity'],
                    'purchase',
                    $purchase,
                    $purchase->reference_label,
                    (float) $line['unit_cost'],
                    $purchase->document_number,
                    $header['guide_document'] ?? null,
                );

                // La variante queda con el último costo de compra (si se indicó)
                if ($line['unit_cost'] > 0) {
                    ProductVariant::whereKey($line['product_variant_id'])->update(['purchase_price' => $line['unit_cost']]);
                }

                // El modelo queda habilitado en la tienda que recibe la mercadería
                ProductVariant::find($line['product_variant_id'])?->product?->branches()->syncWithoutDetaching([$warehouse->branch_id]);
            }

            return $purchase;
        });
    }

    /** Anula la compra y saca su stock. Falla si alguna prenda ya se vendió o movió. */
    public static function void(Purchase $purchase, ?string $reason = null): Purchase
    {
        return DB::transaction(function () use ($purchase, $reason) {
            $purchase = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if ($purchase->status === 'anulada') {
                throw ValidationException::withMessages(['status' => 'La compra ya está anulada.']);
            }

            foreach ($purchase->items as $item) {
                StockService::move(
                    (int) $item->product_variant_id,
                    (int) $purchase->warehouse_id,
                    -1 * (int) $item->quantity,
                    'purchase_void',
                    $purchase,
                    'Anulación ' . $purchase->reference_label . ($reason ? ": {$reason}" : ''),
                    (float) $item->unit_cost,
                    $purchase->document_number,
                );
            }

            $purchase->update([
                'status' => 'anulada',
                'voided_at' => now(),
                'voided_by' => Auth::id(),
                'notes' => trim(($purchase->notes ? $purchase->notes . "\n" : '') . 'Anulada: ' . ($reason ?: 'sin motivo')),
            ]);

            return $purchase;
        });
    }
}
