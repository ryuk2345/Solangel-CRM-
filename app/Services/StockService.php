<?php

namespace App\Services;

use App\Models\Inventory;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Único punto para mover stock (PLAN_MODA, Etapa 5 / AGENTS.md):
 * bloquea la fila de inventario, impide el stock negativo (decisión 5) y deja el movimiento en el kardex.
 */
class StockService
{
    /**
     * @param  int  $quantity  Con signo: positivo entra, negativo sale.
     *
     * @throws ValidationException si la salida deja el stock en negativo.
     */
    public static function move(int $variantId, int $warehouseId, int $quantity, string $type, ?Model $reference = null, ?string $note = null): StockMovement
    {
        return DB::transaction(function () use ($variantId, $warehouseId, $quantity, $type, $reference, $note) {
            $inventory = Inventory::where('product_variant_id', $variantId)
                ->where('warehouse_id', $warehouseId)
                ->lockForUpdate()
                ->first();

            if (!$inventory) {
                $warehouse = Warehouse::findOrFail($warehouseId);
                Inventory::firstOrCreate(
                    ['product_variant_id' => $variantId, 'warehouse_id' => $warehouseId],
                    ['branch_id' => $warehouse->branch_id, 'stock' => 0],
                );
                $inventory = Inventory::where('product_variant_id', $variantId)
                    ->where('warehouse_id', $warehouseId)
                    ->lockForUpdate()
                    ->first();
            }

            $newStock = (int) $inventory->stock + $quantity;

            if ($newStock < 0) {
                $variant = ProductVariant::with('product')->find($variantId);
                $name = $variant?->display_name ?? "variante {$variantId}";
                $warehouseName = $inventory->warehouse?->name ?? "almacén {$warehouseId}";

                throw ValidationException::withMessages([
                    'stock' => "Stock insuficiente de {$name} en {$warehouseName}: hay {$inventory->stock} y se necesitan " . abs($quantity) . '.',
                ]);
            }

            $inventory->update(['stock' => $newStock]);

            return StockMovement::create([
                'warehouse_id' => $warehouseId,
                'product_variant_id' => $variantId,
                'type' => $type,
                'quantity' => $quantity,
                'balance_after' => $newStock,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'user_id' => auth()->id(),
                'note' => $note,
            ]);
        });
    }

    /** Fija el stock a una cantidad contada (ajuste de inventario). */
    public static function setTo(int $variantId, int $warehouseId, int $countedQuantity, ?string $note = null): ?StockMovement
    {
        if ($countedQuantity < 0) {
            throw ValidationException::withMessages(['stock' => 'El stock contado no puede ser negativo.']);
        }

        $current = self::available($variantId, $warehouseId);
        $diff = $countedQuantity - $current;

        return $diff === 0 ? null : self::move($variantId, $warehouseId, $diff, 'adjustment', null, $note);
    }

    public static function available(int $variantId, int $warehouseId): int
    {
        return (int) Inventory::where('product_variant_id', $variantId)
            ->where('warehouse_id', $warehouseId)
            ->value('stock');
    }

    /**
     * Revisa que todas las líneas tengan stock antes de vender (suma las líneas repetidas).
     *
     * @param  array<int, array{product_variant_id:int, quantity:int}>  $lines
     * @return array<int,string> Mensajes de error (vacío si todo alcanza)
     */
    public static function shortages(array $lines, int $warehouseId): array
    {
        $needed = [];
        foreach ($lines as $line) {
            $id = (int) ($line['product_variant_id'] ?? 0);
            if ($id) {
                $needed[$id] = ($needed[$id] ?? 0) + (int) ($line['quantity'] ?? 0);
            }
        }

        $errors = [];
        foreach ($needed as $variantId => $qty) {
            $variant = ProductVariant::with('product')->find($variantId);
            if (!$variant || $variant->product?->is_service) {
                continue;
            }
            $available = self::available($variantId, $warehouseId);
            if ($qty > $available) {
                $errors[] = "{$variant->display_name}: hay {$available}, se piden {$qty}.";
            }
        }

        return $errors;
    }
}
