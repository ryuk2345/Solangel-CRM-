<?php

namespace App\Services;

use App\Filament\Pages\AccountsReceivable;
use App\Filament\Pages\LowStockReport;
use App\Filament\Resources\CashRegisterResource;
use App\Filament\Resources\SaleResource;
use App\Filament\Resources\StockTransferResource;
use App\Models\CashRegister;
use App\Models\CompanySetting;
use App\Models\Inventory;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Models\WholesalePriceRequest;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Servicio centralizado de métricas y consultas para el Panel (Dashboard).
 * Orientado a acción: "¿cómo vamos?" y "¿qué tengo que hacer?".
 */
class DashboardMetrics
{
    /**
     * Ventas netas de hoy en S/ con IGV y cantidad de comprobantes emitidos.
     *
     * @return array{amount: float, count: int}
     */
    public static function todaySales(?int $branchId = null, ?int $userId = null): array
    {
        $salesQuery = Sale::query()
            ->whereNotIn('sunat_status', ['anulado', 'rechazado'])
            ->whereDate('issue_date', Carbon::today())
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($userId, fn ($q) => $q->where('user_id', $userId));

        $count = (clone $salesQuery)->count();
        $salesGross = (float) (clone $salesQuery)->sum('total');

        $returns = (float) SaleReturn::query()
            ->whereNotIn('sunat_status', ['rechazado'])
            ->whereDate('created_at', Carbon::today())
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->sum('total');

        return [
            'amount' => max(0.0, round($salesGross - $returns, 2)),
            'count' => $count,
        ];
    }

    /**
     * Ventas netas del mes en curso con IGV y comparación vs. mes anterior al mismo día.
     * Solo devuelve comparación si el mes anterior tuvo ventas en ese periodo.
     *
     * @return array{amount: float, comparison_label: ?string}
     */
    public static function monthSales(?int $branchId = null): array
    {
        $startOfMonth = Carbon::now()->startOfMonth();
        $now = Carbon::now();

        $currentGross = (float) Sale::query()
            ->whereNotIn('sunat_status', ['anulado', 'rechazado'])
            ->whereBetween('issue_date', [$startOfMonth, $now])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->sum('total');

        $currentReturns = (float) SaleReturn::query()
            ->whereNotIn('sunat_status', ['rechazado'])
            ->whereBetween('created_at', [$startOfMonth, $now])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->sum('total');

        $currentNet = max(0.0, round($currentGross - $currentReturns, 2));

        // Mes anterior hasta el mismo día del mes
        $prevStart = Carbon::now()->subMonth()->startOfMonth();
        $prevNow = Carbon::now()->subMonth();

        $prevGross = (float) Sale::query()
            ->whereNotIn('sunat_status', ['anulado', 'rechazado'])
            ->whereBetween('issue_date', [$prevStart, $prevNow])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->sum('total');

        $prevReturns = (float) SaleReturn::query()
            ->whereNotIn('sunat_status', ['rechazado'])
            ->whereBetween('created_at', [$prevStart, $prevNow])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->sum('total');

        $prevNet = max(0.0, round($prevGross - $prevReturns, 2));

        $comparisonLabel = null;
        if ($prevNet > 0) {
            $change = round((($currentNet - $prevNet) / $prevNet) * 100, 1);
            $sign = $change > 0 ? '+' : '';
            $dayOfMonth = Carbon::now()->day;
            $comparisonLabel = "{$sign}{$change}% vs. mes anterior al día {$dayOfMonth}";
        }

        return [
            'amount' => $currentNet,
            'comparison_label' => $comparisonLabel,
        ];
    }

    /**
     * Margen del mes: Ventas netas sin IGV menos Costo de ventas del Kardex (sin IGV).
     *
     * @return array{sales_net: float, cost_of_sales: float, margin_amount: float, margin_percent: float}
     */
    public static function monthMargin(?int $branchId = null): array
    {
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        $salesSubtotal = (float) DB::table('sales')
            ->whereNotIn('sunat_status', ['anulado', 'rechazado'])
            ->whereBetween('issue_date', [$startOfMonth, $endOfMonth])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->sum('subtotal');

        $returnsTotal = (float) DB::table('sale_returns')
            ->whereNotIn('sunat_status', ['rechazado'])
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->sum('total');

        $returnsSubtotal = round($returnsTotal / 1.18, 2);
        $netSalesSinIgv = max(0.0, round($salesSubtotal - $returnsSubtotal, 2));

        $warehouseIds = $branchId
            ? Warehouse::where('branch_id', $branchId)->pluck('id')->all()
            : null;

        $costOfSales = (float) DB::table('stock_movements')
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->when($warehouseIds, fn ($q) => $q->whereIn('warehouse_id', $warehouseIds))
            ->sum(DB::raw("CASE 
                WHEN type = 'sale' THEN ABS(total_cost)
                WHEN type IN ('sale_void', 'exchange_in', 'credit_note') THEN -ABS(total_cost)
                ELSE 0 END"));

        $costOfSales = max(0.0, round($costOfSales, 2));
        $marginAmount = round($netSalesSinIgv - $costOfSales, 2);
        $marginPercent = $netSalesSinIgv > 0 ? round(($marginAmount / $netSalesSinIgv) * 100, 1) : 0.0;

        return [
            'sales_net' => $netSalesSinIgv,
            'cost_of_sales' => $costOfSales,
            'margin_amount' => $marginAmount,
            'margin_percent' => $marginPercent,
        ];
    }

    /**
     * Resumen de cuentas por cobrar: saldo total, saldo vencido y clientes en mora.
     *
     * @return array{total: float, overdue: float, overdue_clients: int}
     */
    public static function receivableSummary(?int $branchId = null): array
    {
        $baseQuery = Sale::where('credit_balance', '>', 0)
            ->whereNotIn('sunat_status', ['anulado', 'rechazado'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));

        $totalReceivable = (float) (clone $baseQuery)->sum('credit_balance');

        $overdueQuery = (clone $baseQuery)->whereDate('due_date', '<', Carbon::today());
        $overdueAmount = (float) (clone $overdueQuery)->sum('credit_balance');
        $overdueClients = (int) (clone $overdueQuery)->distinct('customer_id')->count('customer_id');

        return [
            'total' => round($totalReceivable, 2),
            'overdue' => round($overdueAmount, 2),
            'overdue_clients' => $overdueClients,
        ];
    }

    /**
     * Saldo valorizado actual de todo el inventario según el Kardex.
     */
    public static function valuedInventoryTotal(?int $branchId = null): float
    {
        $warehouseIds = $branchId
            ? Warehouse::where('branch_id', $branchId)->pluck('id')->all()
            : null;

        $total = (float) DB::table('stock_movements')
            ->whereIn('id', function ($q) use ($warehouseIds) {
                $q->selectRaw('MAX(id)')
                    ->from('stock_movements')
                    ->when($warehouseIds, fn ($w) => $w->whereIn('warehouse_id', $warehouseIds))
                    ->groupBy('product_variant_id', 'warehouse_id');
            })
            ->sum('balance_total_cost');

        return max(0.0, round($total, 2));
    }

    /**
     * Items que requieren atención operativa inmediata.
     * Solo genera filas para tipos con casos reales (> 0).
     *
     * @return Collection<int, object{id: string, issue: string, detail: string, count: int, url: string, color: string, icon: string}>
     */
    public static function attentionItems(?int $branchId = null, bool $forContador = false): Collection
    {
        $items = collect();

        // 1. Aprobaciones de precio pendientes (solo Administrador)
        if (!$forContador) {
            $pendingApprovals = WholesalePriceRequest::where('status', 'pendiente')
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->count();

            if ($pendingApprovals > 0) {
                $url = SaleResource::getUrl('index');
                $items->push((object) [
                    'id' => 'wholesale_approvals',
                    'issue' => 'Aprobaciones de precio pendientes',
                    'detail' => "{$pendingApprovals} " . ($pendingApprovals === 1 ? 'solicitud mayorista por revisar' : 'solicitudes mayoristas por revisar'),
                    'count' => $pendingApprovals,
                    'url' => $url,
                    'color' => 'warning',
                    'icon' => 'heroicon-m-check-badge',
                ]);
            }
        }

        // 2. Comprobantes no aceptados por SUNAT (pendientes o rechazados)
        $sunatQuery = Sale::whereIn('document_type', ['01', '03'])
            ->whereIn('sunat_status', ['pendiente', 'rechazado'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));
        $sunatCount = $sunatQuery->count();

        if ($sunatCount > 0) {
            $url = SaleResource::getUrl('index');
            $items->push((object) [
                'id' => 'sunat_issues',
                'issue' => 'Comprobantes no aceptados por SUNAT',
                'detail' => "{$sunatCount} " . ($sunatCount === 1 ? 'comprobante pendiente o rechazado' : 'comprobantes pendientes o rechazados'),
                'count' => $sunatCount,
                'url' => $url,
                'color' => 'danger',
                'icon' => 'heroicon-m-exclamation-triangle',
            ]);
        }

        // 3. Créditos vencidos
        $overdueQuery = Sale::where('credit_balance', '>', 0)
            ->whereNotIn('sunat_status', ['anulado', 'rechazado'])
            ->whereDate('due_date', '<', Carbon::today())
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));
        $overdueCount = $overdueQuery->count();
        $overdueAmount = (float) (clone $overdueQuery)->sum('credit_balance');
        $clientCount = (int) (clone $overdueQuery)->distinct('customer_id')->count('customer_id');

        if ($overdueCount > 0) {
            $url = AccountsReceivable::getUrl();
            $items->push((object) [
                'id' => 'overdue_credits',
                'issue' => 'Créditos vencidos',
                'detail' => "{$clientCount} " . ($clientCount === 1 ? 'cliente en mora' : 'clientes en mora') . ' · S/ ' . number_format($overdueAmount, 2),
                'count' => $overdueCount,
                'url' => $url,
                'color' => 'danger',
                'icon' => 'heroicon-m-clock',
            ]);
        }

        // 4 y 5 solo aplican al Administrador (el Contador solo ve SUNAT y créditos)
        if (!$forContador) {
            // 4. Cajas abiertas de días anteriores o cierres con diferencia
            $cajasViejas = CashRegister::where('status', 'abierta')
                ->whereDate('opened_at', '<', Carbon::today())
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->count();

            $cierresConDiff = CashRegister::where('status', 'cerrada')
                ->whereDate('closed_at', '>=', Carbon::today()->subDays(7))
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->where(fn ($q) => $q->whereNotNull('difference')->where('difference', '!=', 0))
                ->count();

            $totalCajas = $cajasViejas + $cierresConDiff;
            if ($totalCajas > 0) {
                $detailParts = [];
                if ($cajasViejas > 0) {
                    $detailParts[] = "{$cajasViejas} abierta(s) de días anteriores";
                }
                if ($cierresConDiff > 0) {
                    $detailParts[] = "{$cierresConDiff} cierre(s) con descuadre";
                }
                $url = CashRegisterResource::getUrl('index');
                $items->push((object) [
                    'id' => 'cash_register_issues',
                    'issue' => 'Cajas de días anteriores o con descuadre',
                    'detail' => implode(' · ', $detailParts),
                    'count' => $totalCajas,
                    'url' => $url,
                    'color' => 'danger',
                    'icon' => 'heroicon-m-lock-closed',
                ]);
            }

            // 5. Traspasos en ruta hace más de 24 h
            $delayedTransfers = StockTransfer::where('status', 'en_transito')
                ->where('sent_at', '<', Carbon::now()->subHours(24))
                ->when($branchId, fn ($q) => $q->where(fn ($w) => $w->where('origin_branch_id', $branchId)->orWhere('destination_branch_id', $branchId)))
                ->count();

            if ($delayedTransfers > 0) {
                $url = StockTransferResource::getUrl('index');
                $items->push((object) [
                    'id' => 'delayed_transfers',
                    'issue' => 'Traspasos en ruta demorados (> 24 h)',
                    'detail' => "{$delayedTransfers} " . ($delayedTransfers === 1 ? 'traspaso sin recepción' : 'traspasos sin recepción'),
                    'count' => $delayedTransfers,
                    'url' => $url,
                    'color' => 'warning',
                    'icon' => 'heroicon-m-truck',
                ]);
            }
        }

        return $items;
    }

    /**
     * Tabla "Reponer primero": máximo 10 variantes con stock <= umbral,
     * ordenadas por días de stock = stock / (unidades vendidas en 30 días / 30).
     * Si no se vendió en 30 días, va al final con "sin ventas".
     *
     * @return Collection<int, object{id: int, product_name: string, size: string, color_name: string, location_name: string, stock: int, sold_30d: int, days_of_stock: ?float}>
     */
    public static function restockPriorityList(?int $branchId = null): Collection
    {
        $global = CompanySetting::lowStockThreshold();

        $inventories = Inventory::query()
            ->with(['productVariant.product', 'warehouse.branch'])
            ->whereHas('productVariant.product', fn ($p) => $p->where('is_service', false))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereRaw(
                'inventories.stock <= COALESCE((SELECT p.low_stock_threshold FROM product_variants v '
                . 'INNER JOIN products p ON p.id = v.product_id WHERE v.id = inventories.product_variant_id), ?)',
                [$global],
            )
            ->get();

        if ($inventories->isEmpty()) {
            return collect();
        }

        // Unidades vendidas en los últimos 30 días por variante
        $since30d = Carbon::now()->subDays(30)->startOfDay();
        $sold30d = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->whereNotIn('s.sunat_status', ['anulado', 'rechazado'])
            ->where('s.issue_date', '>=', $since30d)
            ->groupBy('si.product_variant_id')
            ->selectRaw('si.product_variant_id, SUM(si.quantity) as sold_qty')
            ->pluck('sold_qty', 'product_variant_id');

        $returned30d = DB::table('sale_return_items as ri')
            ->join('sale_returns as sr', 'sr.id', '=', 'ri.sale_return_id')
            ->whereNotIn('sr.sunat_status', ['rechazado'])
            ->where('sr.created_at', '>=', $since30d)
            ->groupBy('ri.product_variant_id')
            ->selectRaw('ri.product_variant_id, SUM(ri.quantity) as ret_qty')
            ->pluck('ret_qty', 'product_variant_id');

        $rows = collect();
        foreach ($inventories as $inv) {
            $variant = $inv->productVariant;
            $product = $variant?->product;
            if (!$variant || !$product) {
                continue;
            }

            $vId = $variant->id;
            $gross = (int) ($sold30d[$vId] ?? 0);
            $ret = (int) ($returned30d[$vId] ?? 0);
            $netSold30d = max(0, $gross - $ret);

            $stock = (int) $inv->stock;
            $daysOfStock = null;
            if ($netSold30d > 0) {
                $dailyRate = $netSold30d / 30.0;
                $daysOfStock = round($stock / $dailyRate, 1);
            }

            $location = $inv->warehouse?->name ?? 'Almacén';
            if ($inv->branch && !$branchId) {
                $location = "{$inv->branch->name} · {$location}";
            }

            $rows->push((object) [
                'id' => (int) $inv->id,
                'product_name' => $product->name,
                'size' => $variant->size ?: 'Única',
                'color_name' => $variant->color_name ?: 'Único',
                'location_name' => $location,
                'stock' => $stock,
                'sold_30d' => $netSold30d,
                'days_of_stock' => $daysOfStock,
            ]);
        }

        // Ordenar: primero los que tienen ventas (días de stock asc), al final los que no tienen ventas (stock asc)
        return $rows->sortBy(function ($item) {
            return [
                $item->days_of_stock === null ? 1 : 0,
                $item->days_of_stock ?? $item->stock,
            ];
        })->take(10)->values();
    }

    /**
     * Solicitudes de precio del vendedor (pendientes o resueltas hoy).
     *
     * @return Collection<int, WholesalePriceRequest>
     */
    public static function sellerPriceRequestsToday(int $userId): Collection
    {
        return WholesalePriceRequest::with('customer')
            ->where('requested_by', $userId)
            ->where(function ($q) {
                $q->where('status', 'pendiente')
                    ->orWhereDate('decided_at', Carbon::today())
                    ->orWhereDate('created_at', Carbon::today());
            })
            ->latest()
            ->take(5)
            ->get();
    }

    /**
     * Traspasos en ruta por recibir en la tienda o almacén del usuario.
     *
     * @return Collection<int, StockTransfer>
     */
    public static function incomingTransfers(?int $branchId = null): Collection
    {
        if (!$branchId) {
            return collect();
        }

        return StockTransfer::with(['originBranch', 'originWarehouse', 'items'])
            ->where('status', 'en_transito')
            ->where('destination_branch_id', $branchId)
            ->latest('sent_at')
            ->get();
    }

    /**
     * Unidades totales de stock físico en inventario.
     */
    public static function stockUnitsCount(?int $branchId = null): int
    {
        return (int) Inventory::query()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->sum('stock');
    }

    /**
     * Total de modelos activos (productos no servicio).
     */
    public static function modelsCount(?int $branchId = null): int
    {
        return (int) \App\Models\Product::query()
            ->where('is_service', false)
            ->when($branchId, fn ($q) => $q->whereHas('branches', fn ($b) => $b->where('branches.id', $branchId)))
            ->count();
    }

    /**
     * Total de variantes activas.
     */
    public static function variantsCount(?int $branchId = null): int
    {
        return (int) ProductVariant::query()
            ->where('is_active', true)
            ->whereHas('product', fn ($p) => $p->where('is_service', false))
            ->when($branchId, fn ($q) => $q->whereHas('product.branches', fn ($b) => $b->where('branches.id', $branchId)))
            ->count();
    }

    /**
     * Cantidad de variantes en alerta de stock (<= umbral de bajo stock).
     */
    public static function stockAlertsCount(?int $branchId = null): int
    {
        return (int) Inventory::query()
            ->lowStock()
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->count();
    }

    /**
     * Cantidad de cajas abiertas actualmente.
     */
    public static function openCashRegistersCount(?int $branchId = null): int
    {
        return (int) CashRegister::query()
            ->where('status', 'abierta')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->count();
    }

    /**
     * Top de prendas más vendidas del mes.
     *
     * @return Collection<int, object{product_name: string, color_name: string, size_name: string, units_sold: int, total_amount: float}>
     */
    public static function topSellingProductsThisMonth(?int $branchId = null, int $limit = 10): Collection
    {
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        return DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->join('product_variants as pv', 'pv.id', '=', 'si.product_variant_id')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->leftJoin('colors as c', 'c.id', '=', 'pv.color_id')
            ->leftJoin('sizes as sz', 'sz.id', '=', 'pv.size_id')
            ->whereNotIn('s.sunat_status', ['anulado', 'rechazado'])
            ->whereBetween('s.issue_date', [$startOfMonth, $endOfMonth])
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
            ->groupBy('pv.id', 'p.name', 'c.name', 'sz.name', 'pv.size', 'pv.color_name')
            ->selectRaw('
                p.name as product_name,
                COALESCE(c.name, pv.color_name, "Único") as color_name,
                COALESCE(sz.name, pv.size, "Única") as size_name,
                CAST(SUM(si.quantity) AS SIGNED) as units_sold,
                CAST(SUM(si.total) AS DECIMAL(10,2)) as total_amount
            ')
            ->orderByDesc('units_sold')
            ->limit($limit)
            ->get();
    }
}
