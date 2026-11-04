<?php

namespace App\Services;

use App\Models\Category;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class KardexService
{
    /**
     * Obtiene el reporte del kardex valorizado agrupado por categoría y SKU.
     *
     * @param  array{
     *     period?: string,
     *     warehouse_id?: int|null,
     *     category_id?: int|null,
     *     product_id?: int|null,
     *     search?: string|null,
     *     group_sales_by_day?: bool
     * }  $filters
     * @return array{
     *     period_label: string,
     *     consolidated: array{
     *         initial_cost: float,
     *         entries_cost: float,
     *         final_cost: float,
     *         cost_of_sales: float,
     *         difference: float
     *     },
     *     categories: array<string, array<int, mixed>>
     * }
     */
    public static function getReport(array $filters): array
    {
        $period = $filters['period'] ?? now()->format('Y-m');
        $date = Carbon::createFromFormat('Y-m', $period)->startOfMonth();
        $startOfMonth = $date->copy()->startOfMonth();
        $endOfMonth = $date->copy()->endOfMonth();

        $warehouseId = !empty($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;
        $categoryId = !empty($filters['category_id']) ? (int) $filters['category_id'] : null;
        $productId = !empty($filters['product_id']) ? (int) $filters['product_id'] : null;
        $search = !empty($filters['search']) ? trim($filters['search']) : null;
        $groupSales = !isset($filters['group_sales_by_day']) || (bool) $filters['group_sales_by_day'];

        // Seleccionar variantes que aplican a los filtros
        $variantQuery = ProductVariant::with(['product.category', 'color', 'size'])
            ->whereHas('product', fn ($q) => $q->where('is_service', false));

        if ($productId) {
            $variantQuery->where('product_id', $productId);
        }
        if ($categoryId) {
            $variantQuery->whereHas('product', fn ($q) => $q->where('category_id', $categoryId));
        }
        if ($search) {
            $variantQuery->where(function ($q) use ($search) {
                $q->where('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%"));
            });
        }

        $variants = $variantQuery->get();

        // Almacenes a considerar
        $warehouses = $warehouseId
            ? Warehouse::whereKey($warehouseId)->get()
            : Warehouse::where('is_active', true)->get();

        $consolidatedInitial = 0.0;
        $consolidatedEntries = 0.0;
        $consolidatedFinal = 0.0;
        $consolidatedCostOfSales = 0.0;

        $categoriesBlocks = [];

        foreach ($variants as $variant) {
            $categoryName = $variant->product?->category?->name ?? 'Sin categoría';

            foreach ($warehouses as $wh) {
                $block = self::buildVariantBlock($variant, $wh, $startOfMonth, $endOfMonth, $groupSales);
                // Si no hay saldo inicial y no hubo movimientos en el mes, omitir bloque vacío a menos que se haya buscado específicamente
                if ($block['initial']['qty'] == 0 && empty($block['rows']) && !$search && !$productId) {
                    continue;
                }

                $consolidatedInitial += $block['summary']['initial_cost'];
                $consolidatedEntries += $block['summary']['entries_cost'];
                $consolidatedFinal += $block['summary']['final_cost'];
                $consolidatedCostOfSales += $block['summary']['cost_of_sales'];

                $categoriesBlocks[$categoryName][] = $block;
            }
        }

        $diff = round(($consolidatedInitial + $consolidatedEntries - $consolidatedFinal) - $consolidatedCostOfSales, 2);

        return [
            'period_label' => ucfirst($date->translatedFormat('F Y')),
            'period_raw' => $period,
            'consolidated' => [
                'initial_cost' => round($consolidatedInitial, 2),
                'entries_cost' => round($consolidatedEntries, 2),
                'final_cost' => round($consolidatedFinal, 2),
                'cost_of_sales' => round($consolidatedCostOfSales, 2),
                'difference' => $diff,
            ],
            'categories' => $categoriesBlocks,
        ];
    }

    /**
     * Construye un bloque de Kardex para una variante y un almacén.
     */
    private static function buildVariantBlock(
        ProductVariant $variant,
        Warehouse $warehouse,
        Carbon $startOfMonth,
        Carbon $endOfMonth,
        bool $groupSales
    ): array {
        // 1. Saldo inicial: último movimiento antes del inicio del mes
        $lastBefore = StockMovement::where('product_variant_id', $variant->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('created_at', '<', $startOfMonth)
            ->latest('created_at')
            ->latest('id')
            ->first();

        $initialQty = $lastBefore ? (int) $lastBefore->balance_after : 0;
        $initialCost = $lastBefore ? (float) $lastBefore->balance_total_cost : 0.0;
        $initialUnitCost = $initialQty > 0 ? (float) $lastBefore->balance_unit_cost : 0.0;

        // 2. Movimientos del mes
        $movements = StockMovement::where('product_variant_id', $variant->id)
            ->where('warehouse_id', $warehouse->id)
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $rows = [];
        $runningQty = $initialQty;
        $runningTotalCost = $initialCost;
        $runningUnitCost = $initialUnitCost;

        $itemNum = 1;

        $sumEntriesQty = 0;
        $sumEntriesCost = 0.0;
        $sumExitsQty = 0;
        $sumExitsCost = 0.0;

        foreach ($movements as $m) {
            $isNegativeEntry = $m->type === 'purchase_void';
            $isNegativeExit = in_array($m->type, ['sale_void', 'exchange_in']);
            $isEntry = in_array($m->type, ['purchase', 'initial', 'transfer_in', 'transfer_return']) || ($m->type === 'adjustment' && $m->quantity > 0) || $isNegativeEntry;

            $entryQty = null;
            $entryUnitCost = null;
            $entryTotalCost = null;

            $exitQty = null;
            $exitUnitCost = null;
            $exitTotalCost = null;

            if ($isEntry) {
                if ($isNegativeEntry) {
                    $entryQty = -abs((int) $m->quantity);
                    $entryUnitCost = (float) $m->unit_cost;
                    $entryTotalCost = -abs((float) $m->total_cost);
                } else {
                    $entryQty = abs((int) $m->quantity);
                    $entryUnitCost = (float) $m->unit_cost;
                    $entryTotalCost = abs((float) $m->total_cost);
                }
                $sumEntriesQty += $entryQty;
                $sumEntriesCost += $entryTotalCost;
            } else {
                if ($isNegativeExit) {
                    $exitQty = -abs((int) $m->quantity);
                    $exitUnitCost = (float) $m->unit_cost;
                    $exitTotalCost = -abs((float) $m->total_cost);
                } else {
                    $exitQty = abs((int) $m->quantity);
                    $exitUnitCost = (float) $m->unit_cost;
                    $exitTotalCost = abs((float) $m->total_cost);
                }
                $sumExitsQty += $exitQty;
                $sumExitsCost += $exitTotalCost;
            }

            $runningQty = (int) $m->balance_after;
            $runningTotalCost = (float) $m->balance_total_cost;
            $runningUnitCost = (float) $m->balance_unit_cost;

            $detail = $m->note ?: $m->type_label;
            if ($m->type === 'purchase') {
                $detail = 'Compra' . ($m->payment_document ? " ({$m->payment_document})" : '');
            } elseif ($m->type === 'sale') {
                $detail = 'Venta' . ($m->payment_document ? " ({$m->payment_document})" : '');
            }

            $rows[] = [
                'item' => $itemNum++,
                'date' => $m->created_at->format('d/m/Y'),
                'guide' => $m->guide_document ?? '----',
                'doc' => $m->payment_document ?? '----',
                'detail' => $detail,
                'entry_qty' => $entryQty,
                'entry_unit' => $entryUnitCost,
                'entry_total' => $entryTotalCost,
                'exit_qty' => $exitQty,
                'exit_unit' => $exitUnitCost,
                'exit_total' => $exitTotalCost,
                'balance_qty' => $runningQty,
                'balance_unit' => $runningUnitCost,
                'balance_total' => $runningTotalCost,
            ];
        }

        $costOfSales = round($sumExitsCost, 2);
        $diff = round(($initialCost + $sumEntriesCost - $runningTotalCost) - $costOfSales, 2);

        return [
            'sku' => $variant->sku,
            'category_name' => $variant->product?->category?->name ?? 'Sin categoría',
            'product_name' => $variant->product?->name ?? 'Prenda',
            'size_name' => $variant->size?->name ?? $variant->size ?? 'U',
            'color_name' => $variant->color?->name ?? $variant->color_name ?? 'Único',
            'warehouse_name' => $warehouse->name,
            'title' => "{$variant->sku} - " . ($variant->product?->category?->name ?? 'General') . " - {$variant->product?->name} - " . ($variant->size?->name ?? 'U') . " - " . ($variant->color?->name ?? 'Único'),
            'initial' => [
                'label' => 'SALDO INICIAL AL MES DE ' . mb_strtoupper($startOfMonth->translatedFormat('F Y')),
                'qty' => $initialQty,
                'unit_cost' => $initialUnitCost,
                'total_cost' => $initialCost,
            ],
            'rows' => $rows,
            'totals' => [
                'entries_qty' => $sumEntriesQty,
                'entries_cost' => round($sumEntriesCost, 2),
                'exits_qty' => $sumExitsQty,
                'exits_cost' => round($sumExitsCost, 2),
                'balance_qty' => $runningQty,
                'balance_unit' => $runningUnitCost,
                'balance_total' => round($runningTotalCost, 2),
            ],
            'summary' => [
                'initial_cost' => round($initialCost, 2),
                'entries_cost' => round($sumEntriesCost, 2),
                'final_cost' => round($runningTotalCost, 2),
                'cost_of_sales' => $costOfSales,
                'difference' => $diff,
            ],
        ];
    }
}

