<?php

namespace App\Services;

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
 * 
 * Reglas de negocio:
 * - Ventas válidas: boletas (03), facturas (01) y notas de venta (NV) emitidas.
 * - Excluye ventas anuladas y rechazadas por SUNAT.
 * - Resta notas de crédito y devoluciones de clientes del periodo.
 * - Ventas de hoy y gráfico van en S/ con IGV (ingresos reales a caja).
 * - Margen del mes = Ventas del mes SIN IGV − Costo de ventas del Kardex (sin IGV).
 */
class DashboardMetrics
{
    /**
     * Ventas netas de hoy en S/ con IGV (ventas brutas menos devoluciones).
     */
    public static function todaySales(?int $branchId = null): float
    {
        $salesGross = (float) Sale::query()
            ->whereNotIn('sunat_status', ['anulado', 'rechazado'])
            ->whereDate('issue_date', Carbon::today())
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->sum('total');

        $returns = (float) SaleReturn::query()
            ->whereNotIn('sunat_status', ['rechazado'])
            ->whereDate('created_at', Carbon::today())
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->sum('total');

        return max(0.0, round($salesGross - $returns, 2));
    }

    /**
     * Comparación de ventas de hoy vs. el mismo día de la semana pasada.
     *
     * @return array{percentage: ?float, label: string, trend: 'up'|'down'|'neutral'}
     */
    public static function todaySalesComparison(?int $branchId = null): array
    {
        $today = self::todaySales($branchId);

        $lastWeekDate = Carbon::today()->subDays(7);
        $lastWeekGross = (float) Sale::query()
            ->whereNotIn('sunat_status', ['anulado', 'rechazado'])
            ->whereDate('issue_date', $lastWeekDate)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->sum('total');

        $lastWeekReturns = (float) SaleReturn::query()
            ->whereNotIn('sunat_status', ['rechazado'])
            ->whereDate('created_at', $lastWeekDate)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->sum('total');

        $lastWeekNet = max(0.0, round($lastWeekGross - $lastWeekReturns, 2));
        $dayName = Carbon::today()->translatedFormat('D');

        if ($lastWeekNet <= 0) {
            return [
                'percentage' => null,
                'label' => $today > 0 ? "Sin ventas el {$dayName}. pasado" : "Sin ventas registradas",
                'trend' => $today > 0 ? 'up' : 'neutral',
            ];
        }

        $change = round((($today - $lastWeekNet) / $lastWeekNet) * 100, 1);
        $trend = $change > 0 ? 'up' : ($change < 0 ? 'down' : 'neutral');
        $sign = $change > 0 ? '+' : '';

        return [
            'percentage' => $change,
            'label' => "{$sign}{$change}% vs. {$dayName}. pasado",
            'trend' => $trend,
        ];
    }

    /**
     * Unidades vendidas hoy netas de prendas devueltas.
     */
    public static function todayUnitsSold(?int $branchId = null): int
    {
        $saleIds = Sale::query()
            ->whereNotIn('sunat_status', ['anulado', 'rechazado'])
            ->whereDate('issue_date', Carbon::today())
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->pluck('id');

        $grossUnits = (int) SaleItem::whereIn('sale_id', $saleIds)
            ->whereHas('productVariant.product', fn ($p) => $p->where('is_service', false))
            ->sum('quantity');

        $returnIds = SaleReturn::query()
            ->whereNotIn('sunat_status', ['rechazado'])
            ->whereDate('created_at', Carbon::today())
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->pluck('id');

        $returnUnits = (int) SaleReturnItem::whereIn('sale_return_id', $returnIds)
            ->whereHas('productVariant.product', fn ($p) => $p->where('is_service', false))
            ->sum('quantity');

        return max(0, $grossUnits - $returnUnits);
    }

    /**
     * Total de variantes activas de catálogo (modelos, tallas y colores).
     */
    public static function activeVariantsCount(): int
    {
        return (int) ProductVariant::where('is_active', true)
            ->whereHas('product', fn ($p) => $p->where('is_service', false))
            ->count();
    }

    /**
     * Conteo de registros con stock bajo o agotado según umbral del modelo o de la empresa.
     * Coincide exactamente con el alcance de Inventory::scopeLowStock.
     */
    public static function stockAlertsCount(?int $branchId = null): int
    {
        $global = CompanySetting::lowStockThreshold();

        return (int) Inventory::query()
            ->whereHas('productVariant.product', fn ($p) => $p->where('is_service', false))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereRaw(
                'inventories.stock <= COALESCE((SELECT p.low_stock_threshold FROM product_variants v '
                . 'INNER JOIN products p ON p.id = v.product_id WHERE v.id = inventories.product_variant_id), ?)',
                [$global],
            )
            ->count();
    }

    /**
     * Estado de la caja de la tienda: abierta con saldo actual, o cerrada con estado del último cierre.
     *
     * @return array{is_open: bool, label: string, description: string, color: string, icon: string}
     */
    public static function cashRegisterStatus(?int $branchId = null): array
    {
        $query = CashRegister::query();
        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $cajaAbierta = (clone $query)->where('status', 'abierta')->latest('opened_at')->first();

        if ($cajaAbierta) {
            $monto = (float) ($cajaAbierta->expected_amount ?: $cajaAbierta->initial_amount);
            $usuario = $cajaAbierta->user?->name ?? 'cajero';
            return [
                'is_open' => true,
                'label' => 'Abierta · S/ ' . number_format($monto, 2),
                'description' => "Turno activo por {$usuario}",
                'color' => 'success',
                'icon' => 'heroicon-m-lock-open',
            ];
        }

        $ultimoCierre = (clone $query)->where('status', 'cerrada')->latest('closed_at')->first();

        if ($ultimoCierre) {
            $diff = round((float) $ultimoCierre->actual_amount - (float) $ultimoCierre->expected_amount, 2);
            if (abs($diff) < 0.01) {
                return [
                    'is_open' => false,
                    'label' => 'Cerrada',
                    'description' => 'Cuadrada · S/ ' . number_format((float) $ultimoCierre->actual_amount, 2),
                    'color' => 'gray',
                    'icon' => 'heroicon-m-lock-closed',
                ];
            }

            $sign = $diff > 0 ? '+' : '';
            return [
                'is_open' => false,
                'label' => 'Cerrada',
                'description' => "Descuadre {$sign}S/ " . number_format($diff, 2) . ' en último cierre',
                'color' => 'danger',
                'icon' => 'heroicon-m-exclamation-circle',
            ];
        }

        return [
            'is_open' => false,
            'label' => 'Cerrada',
            'description' => 'Sin turnos registrados',
            'color' => 'gray',
            'icon' => 'heroicon-m-lock-closed',
        ];
    }

    /**
     * Traspasos en ruta para la tienda o red de tiendas.
     *
     * @return array{count: int, units: int, description: string, color: string, icon: string}
     */
    public static function transfersInTransit(?int $branchId = null): array
    {
        $transferQuery = StockTransfer::where('status', 'en_transito');
        if ($branchId) {
            $transferQuery->where(fn ($q) => $q->where('origin_branch_id', $branchId)->orWhere('destination_branch_id', $branchId));
        }

        $transfers = (clone $transferQuery)->with(['items', 'destinationBranch'])->get();
        $count = $transfers->count();
        $totalUnits = (int) $transfers->sum(fn ($t) => $t->items->sum('quantity_sent'));

        $description = match (true) {
            $count === 0 => 'Sin traspasos pendientes',
            $count === 1 => "{$totalUnits} prendas hacia " . ($transfers->first()->destinationBranch?->name ?? 'destino'),
            default => "{$totalUnits} prendas en {$count} envíos entre tiendas",
        };

        return [
            'count' => $count,
            'units' => $totalUnits,
            'description' => $description,
            'color' => $count > 0 ? 'warning' : 'gray',
            'icon' => 'heroicon-m-truck',
        ];
    }

    /**
     * Ventas de los últimos 14 días con IGV, discriminadas por minorista y mayorista.
     *
     * @return array{labels: string[], retail: float[], wholesale: float[]}
     */
    public static function salesLast14Days(?int $branchId = null): array
    {
        $labels = [];
        $retail = [];
        $wholesale = [];

        for ($i = 13; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i);
            $labels[] = $date->translatedFormat('d/m');

            $items = DB::table('sale_items as si')
                ->join('sales as s', 's.id', '=', 'si.sale_id')
                ->join('product_variants as pv', 'pv.id', '=', 'si.product_variant_id')
                ->join('products as p', 'p.id', '=', 'pv.product_id')
                ->where('p.is_service', false)
                ->whereNotIn('s.sunat_status', ['anulado', 'rechazado'])
                ->whereDate('s.issue_date', $date)
                ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
                ->selectRaw("
                    COALESCE(SUM(CASE WHEN si.price_type = 'wholesale' THEN si.total ELSE 0 END), 0) as wholesale_amount,
                    COALESCE(SUM(CASE WHEN si.price_type != 'wholesale' THEN si.total ELSE 0 END), 0) as retail_amount
                ")
                ->first();

            $returns = DB::table('sale_return_items as ri')
                ->join('sale_returns as sr', 'sr.id', '=', 'ri.sale_return_id')
                ->join('sale_items as si', 'si.id', '=', 'ri.sale_item_id')
                ->whereNotIn('sr.sunat_status', ['rechazado'])
                ->whereDate('sr.created_at', $date)
                ->when($branchId, fn ($q) => $q->where('sr.branch_id', $branchId))
                ->selectRaw("
                    COALESCE(SUM(CASE WHEN si.price_type = 'wholesale' THEN ri.total ELSE 0 END), 0) as wholesale_returned,
                    COALESCE(SUM(CASE WHEN si.price_type != 'wholesale' THEN ri.total ELSE 0 END), 0) as retail_returned
                ")
                ->first();

            $wNet = max(0.0, round(((float) ($items->wholesale_amount ?? 0)) - ((float) ($returns->wholesale_returned ?? 0)), 2));
            $rNet = max(0.0, round(((float) ($items->retail_amount ?? 0)) - ((float) ($returns->retail_returned ?? 0)), 2));

            $wholesale[] = $wNet;
            $retail[] = $rNet;
        }

        return [
            'labels' => $labels,
            'retail' => $retail,
            'wholesale' => $wholesale,
        ];
    }

    /**
     * Top 5 modelos más vendidos del mes en curso, con unidades netas, total facturado y talla más vendida.
     *
     * @return Collection<int, object{id: int, product_id: int, product_name: string, units: int, amount: float, top_size: string}>
     */
    public static function top5ModelsThisMonth(?int $branchId = null): Collection
    {
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        $sold = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->join('product_variants as pv', 'pv.id', '=', 'si.product_variant_id')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->where('p.is_service', false)
            ->whereNotIn('s.sunat_status', ['anulado', 'rechazado'])
            ->whereBetween('s.issue_date', [$startOfMonth, $endOfMonth])
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
            ->selectRaw('p.id as product_id, p.name as product_name, SUM(si.quantity) as gross_units, SUM(si.total) as gross_amount')
            ->groupBy('p.id', 'p.name')
            ->get()
            ->keyBy('product_id');

        if ($sold->isEmpty()) {
            return collect();
        }

        $returned = DB::table('sale_return_items as ri')
            ->join('sale_returns as sr', 'sr.id', '=', 'ri.sale_return_id')
            ->join('product_variants as pv', 'pv.id', '=', 'ri.product_variant_id')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->whereNotIn('sr.sunat_status', ['rechazado'])
            ->whereBetween('sr.created_at', [$startOfMonth, $endOfMonth])
            ->when($branchId, fn ($q) => $q->where('sr.branch_id', $branchId))
            ->selectRaw('p.id as product_id, SUM(ri.quantity) as ret_units, SUM(ri.total) as ret_amount')
            ->groupBy('p.id')
            ->get()
            ->keyBy('product_id');

        $result = collect();
        foreach ($sold as $productId => $row) {
            $ret = $returned->get($productId);
            $netUnits = (int) $row->gross_units - (int) ($ret->ret_units ?? 0);
            $netAmount = (float) $row->gross_amount - (float) ($ret->ret_amount ?? 0);

            if ($netUnits <= 0) {
                continue;
            }

            // Talla más vendida para este modelo en el mes
            $topSize = DB::table('sale_items as si')
                ->join('sales as s', 's.id', '=', 'si.sale_id')
                ->join('product_variants as pv', 'pv.id', '=', 'si.product_variant_id')
                ->where('pv.product_id', $productId)
                ->whereNotIn('s.sunat_status', ['anulado', 'rechazado'])
                ->whereBetween('s.issue_date', [$startOfMonth, $endOfMonth])
                ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
                ->selectRaw("COALESCE(pv.size, 'Única') as size_name, SUM(si.quantity) as qty")
                ->groupBy('size_name')
                ->orderByDesc('qty')
                ->value('size_name') ?: '—';

            $result->push((object) [
                'id' => (int) $productId,
                'product_id' => (int) $productId,
                'product_name' => $row->product_name,
                'units' => $netUnits,
                'amount' => max(0.0, round($netAmount, 2)),
                'top_size' => $topSize,
            ]);
        }

        return $result->sortByDesc('units')->take(5)->values();
    }

    /**
     * Créditos por cobrar vencidos.
     *
     * @return array{amount: float, client_count: int, description: string, color: string}
     */
    public static function overdueCredit(?int $branchId = null): array
    {
        $query = Sale::where('credit_balance', '>', 0)
            ->whereNotIn('sunat_status', ['anulado', 'rechazado'])
            ->whereDate('due_date', '<', Carbon::today())
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));

        $amount = (float) (clone $query)->sum('credit_balance');
        $clientCount = (int) (clone $query)->distinct('customer_id')->count('customer_id');

        return [
            'amount' => round($amount, 2),
            'client_count' => $clientCount,
            'description' => $clientCount > 0
                ? "{$clientCount} " . ($clientCount === 1 ? 'cliente con mora' : 'clientes con mora')
                : 'Al día, sin clientes con mora',
            'color' => $amount > 0 ? 'danger' : 'success',
        ];
    }

    /**
     * Solicitudes de precio mayorista pendientes de aprobación.
     */
    public static function pendingPriceApprovalsCount(?int $branchId = null): int
    {
        return (int) WholesalePriceRequest::where('status', 'pendiente')
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->count();
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

        // Subtotal de devoluciones = total / 1.18
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
     * Determina si hay alguna venta registrada hoy en la tienda especificada.
     */
    public static function todayHasSales(?int $branchId = null): bool
    {
        return Sale::query()
            ->whereNotIn('sunat_status', ['anulado', 'rechazado'])
            ->whereDate('issue_date', Carbon::today())
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->exists();
    }
}
