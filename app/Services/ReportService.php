<?php

namespace App\Services;

use App\Models\CompanySetting;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reportes de la tienda de ropa (Etapa 9). Las ventas son NETAS: se restan las prendas
 * devueltas en cambios y devoluciones. No cuentan ventas anuladas ni rechazadas ni el delivery.
 */
class ReportService
{
    /**
     * Ventas netas agrupadas por una dimensión.
     *
     * @param  string  $by  size | color | season | channel | reason | model
     * @return Collection<int, array{label:string, units:int, amount:float, share:float}>
     */
    public static function salesBy(string $by, Carbon $from, Carbon $to, ?int $branchId = null): Collection
    {
        [$keyExpr, $orderExpr] = match ($by) {
            'size' => ["COALESCE(pv.size, 'Única')", 'MIN(COALESCE(sz.sort_order, 9999))'],
            'color' => ["COALESCE(pv.color_name, 'Único')", 'MIN(COALESCE(co.sort_order, 9999))'],
            'season' => ["COALESCE(se.name, 'Sin temporada')", 'MIN(COALESCE(se.id, 0)) * -1'],
            'channel' => ["CASE WHEN si.price_type = 'wholesale' THEN 'Mayorista' ELSE 'Minorista' END", 'MIN(0)'],
            'reason' => ["CASE si.price_reason WHEN 'quantity' THEN 'Mayorista por cantidad' WHEN 'authorized' THEN 'Mayorista aprobado (1 prenda)' WHEN 'manual' THEN 'Precio a mano (administrador)' WHEN 'exchange' THEN 'Mayorista por cambio' ELSE 'Minorista' END", 'MIN(0)'],
            'model' => ['p.name', 'MIN(0)'],
            default => throw new \InvalidArgumentException("Dimensión no válida: {$by}"),
        };

        $sold = DB::table('sale_items as si')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->join('product_variants as pv', 'pv.id', '=', 'si.product_variant_id')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->leftJoin('sizes as sz', 'sz.id', '=', 'pv.size_id')
            ->leftJoin('colors as co', 'co.id', '=', 'pv.color_id')
            ->leftJoin('seasons as se', 'se.id', '=', 'p.season_id')
            ->where('p.is_service', false)
            ->whereNotIn('s.sunat_status', ['anulado', 'rechazado'])
            ->whereBetween('s.issue_date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
            ->selectRaw("{$keyExpr} as label, SUM(si.quantity) as units, SUM(si.total) as amount, {$orderExpr} as ord")
            ->groupBy('label')
            ->get()->keyBy('label');

        // Devoluciones de esas ventas (mismo periodo de la venta original)
        $returned = DB::table('sale_return_items as ri')
            ->join('sale_items as si', 'si.id', '=', 'ri.sale_item_id')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->join('product_variants as pv', 'pv.id', '=', 'ri.product_variant_id')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->leftJoin('sizes as sz', 'sz.id', '=', 'pv.size_id')
            ->leftJoin('colors as co', 'co.id', '=', 'pv.color_id')
            ->leftJoin('seasons as se', 'se.id', '=', 'p.season_id')
            ->whereNotIn('s.sunat_status', ['anulado', 'rechazado'])
            ->whereBetween('s.issue_date', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
            ->selectRaw("{$keyExpr} as label, SUM(ri.quantity) as units, SUM(ri.total) as amount")
            ->groupBy('label')
            ->get()->keyBy('label');

        $rows = $sold->map(fn ($r) => [
            'label' => (string) $r->label,
            'units' => (int) $r->units - (int) ($returned[$r->label]->units ?? 0),
            'amount' => round((float) $r->amount - (float) ($returned[$r->label]->amount ?? 0), 2),
            'ord' => $r->ord,
        ])->filter(fn ($r) => $r['units'] > 0);

        $totalUnits = max(1, $rows->sum('units'));
        $sorted = $by === 'model' ? $rows->sortByDesc('units') : ($by === 'channel' || $by === 'reason' ? $rows->sortByDesc('amount') : $rows->sortBy('ord'));

        return $sorted->map(fn ($r) => [
            'label' => $r['label'],
            'units' => $r['units'],
            'amount' => $r['amount'],
            'share' => round($r['units'] * 100 / $totalUnits, 1),
        ])->values();
    }

    /**
     * Curva rota: modelo y color que todavía tienen stock, pero con alguna talla agotada.
     *
     * @return Collection<int, array{model:string, color:string, warehouse:string, missing:array, available:array, units:int}>
     */
    public static function brokenCurves(?int $warehouseId = null, ?int $branchId = null): Collection
    {
        $rows = DB::table('inventories as i')
            ->join('product_variants as pv', 'pv.id', '=', 'i.product_variant_id')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->join('warehouses as w', 'w.id', '=', 'i.warehouse_id')
            ->leftJoin('sizes as sz', 'sz.id', '=', 'pv.size_id')
            ->where('p.is_service', false)
            ->whereNotNull('pv.size_id')
            ->when($warehouseId, fn ($q) => $q->where('i.warehouse_id', $warehouseId))
            ->when($branchId, fn ($q) => $q->where('w.branch_id', $branchId))
            ->select('p.name as model', 'pv.color_name as color', 'w.name as warehouse', 'i.warehouse_id', 'pv.product_id',
                'pv.size', 'i.stock', DB::raw('COALESCE(sz.sort_order, 9999) as ord'))
            ->orderBy('ord')
            ->get();

        return $rows->groupBy(fn ($r) => "{$r->product_id}|{$r->color}|{$r->warehouse_id}")
            ->map(function (Collection $sizes) {
                $first = $sizes->first();
                $missing = $sizes->where('stock', '<=', 0)->pluck('size')->values()->all();
                $available = $sizes->where('stock', '>', 0)->map(fn ($s) => "{$s->size}: {$s->stock}")->values()->all();

                return [
                    'model' => $first->model,
                    'color' => $first->color ?: 'Único',
                    'warehouse' => $first->warehouse,
                    'missing' => $missing,
                    'available' => $available,
                    'units' => (int) $sizes->sum('stock'),
                ];
            })
            ->filter(fn ($r) => !empty($r['missing']) && $r['units'] > 0)
            ->sortBy([['units', 'asc']])
            ->values();
    }

    /**
     * Stock sin movimiento: prendas con stock que no se venden hace N días (o nunca desde que entraron).
     *
     * @return Collection<int, array{model:string, color:string, size:string, warehouse:string, stock:int, last_sale:?string, days:int, cost_value:float}>
     */
    public static function deadStock(int $days = 90, ?int $warehouseId = null, ?int $branchId = null): Collection
    {
        $since = now()->subDays($days);

        $lastSale = DB::table('stock_movements')
            ->where('type', 'sale')
            ->selectRaw('product_variant_id, warehouse_id, MAX(created_at) as last_sale')
            ->groupBy('product_variant_id', 'warehouse_id');

        $firstIn = DB::table('stock_movements')
            ->where('quantity', '>', 0)
            ->selectRaw('product_variant_id, warehouse_id, MIN(created_at) as first_in')
            ->groupBy('product_variant_id', 'warehouse_id');

        return DB::table('inventories as i')
            ->join('product_variants as pv', 'pv.id', '=', 'i.product_variant_id')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->join('warehouses as w', 'w.id', '=', 'i.warehouse_id')
            ->leftJoinSub($lastSale, 'ls', fn ($j) => $j->on('ls.product_variant_id', '=', 'i.product_variant_id')->on('ls.warehouse_id', '=', 'i.warehouse_id'))
            ->leftJoinSub($firstIn, 'fi', fn ($j) => $j->on('fi.product_variant_id', '=', 'i.product_variant_id')->on('fi.warehouse_id', '=', 'i.warehouse_id'))
            ->where('p.is_service', false)
            ->where('i.stock', '>', 0)
            ->when($warehouseId, fn ($q) => $q->where('i.warehouse_id', $warehouseId))
            ->when($branchId, fn ($q) => $q->where('w.branch_id', $branchId))
            ->whereRaw('COALESCE(ls.last_sale, fi.first_in, i.created_at) < ?', [$since])
            ->select('p.name as model', 'pv.color_name as color', 'pv.size', 'w.name as warehouse', 'i.stock',
                'ls.last_sale', DB::raw('COALESCE(ls.last_sale, fi.first_in, i.created_at) as since'),
                DB::raw('COALESCE(pv.purchase_price, p.purchase_price, 0) as cost'))
            ->get()
            ->map(fn ($r) => [
                'model' => $r->model,
                'color' => $r->color ?: 'Único',
                'size' => $r->size ?: 'Única',
                'warehouse' => $r->warehouse,
                'stock' => (int) $r->stock,
                'last_sale' => $r->last_sale ? Carbon::parse($r->last_sale)->format('d/m/Y') : null,
                'days' => (int) Carbon::parse($r->since)->diffInDays(now()),
                'cost_value' => round((int) $r->stock * (float) $r->cost, 2),
            ])
            ->sortByDesc('days')
            ->values();
    }

    /** Escribe un CSV (separado por ; para Excel en español). */
    public static function csv(string $filename, array $headers, iterable $rows)
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers, ';');
            foreach ($rows as $row) {
                fputcsv($out, array_values($row), ';');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
