<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\LowStockReport;
use App\Models\CashRegister;
use App\Models\CompanySetting;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockTransfer;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
    protected int | string | array $columnSpan = 'full';
    protected static ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return auth()->user()?->seesAllBranches() || auth()->user()?->can('widget_StatsOverview');
    }

    protected function getStats(): array
    {
        $user = auth()->user();
        $isSuperAdmin = $user?->seesAllBranches() ?? false;
        $isAlmacenero = $user?->hasRole('almacenero') ?? false;
        $branchId = $user?->branch_id ?? 1;

        // 1. Ventas y unidades de hoy
        $salesTodayQuery = Sale::whereDate('created_at', Carbon::today())
            ->where('sunat_status', '!=', 'anulado');

        if (!$isSuperAdmin) {
            $salesTodayQuery->where('branch_id', $branchId);
        }

        $saleIdsToday = (clone $salesTodayQuery)->pluck('id');
        $ventasHoy = (float) (clone $salesTodayQuery)->sum('total');
        $unidadesHoy = (int) SaleItem::whereIn('sale_id', $saleIdsToday)
            ->whereHas('productVariant.product', fn ($p) => $p->where('is_service', false))
            ->sum('quantity');

        // 2. Variantes activas
        $variantesActivas = ProductVariant::whereHas('product', fn ($p) => $p->where('is_service', false))->count();

        // 3. Alertas de stock bajo
        $threshold = (int) (CompanySetting::value('low_stock_threshold') ?: 5);
        $inventoryQuery = Inventory::whereHas('productVariant.product', fn ($p) => $p->where('is_service', false));
        if (!$isSuperAdmin) {
            $inventoryQuery->where('branch_id', $branchId);
        }
        $alertasStock = (clone $inventoryQuery)->where('stock', '<=', $threshold)->count();

        // 4. Caja diaria
        $cajaQuery = CashRegister::where('status', 'abierta');
        if (!$isSuperAdmin) {
            $cajaQuery->where('branch_id', $branchId);
        }
        $cajaAbierta = $cajaQuery->latest('opened_at')->first();
        $cajaLabel = $cajaAbierta ? 'S/ ' . number_format((float) ($cajaAbierta->expected_amount ?: $cajaAbierta->initial_amount), 2) : 'Cerrada';
        $cajaDesc = $cajaAbierta ? 'Turno abierto' : 'Sin turno activo';
        $cajaColor = $cajaAbierta ? 'success' : 'danger';

        // 5. Traspasos en tránsito
        $transferQuery = StockTransfer::where('status', 'en_transito');
        if (!$isSuperAdmin) {
            $transferQuery->where(fn ($q) => $q->where('origin_branch_id', $branchId)->orWhere('destination_branch_id', $branchId));
        }
        $traspasosEnRuta = (clone $transferQuery)->count();
        $traspasosUnidades = (int) (clone $transferQuery)->with('items')->get()->sum(fn ($t) => $t->items->sum('quantity_sent'));

        $stats = [];

        // Tarjetas según rol
        if (!$isAlmacenero) {
            $stats[] = Stat::make('Ventas de hoy', 'S/ ' . number_format($ventasHoy, 2))
                ->description('Total cobrado hoy')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success');

            $stats[] = Stat::make('Unidades vendidas hoy', $unidadesHoy)
                ->description('Prendas entregadas hoy')
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->color('primary');
        }

        $stats[] = Stat::make('Variantes activas', $variantesActivas)
            ->description('Modelos, tallas y colores')
            ->descriptionIcon('heroicon-m-cube')
            ->color('gray');

        $stats[] = Stat::make('Alertas de stock', $alertasStock)
            ->description($alertasStock > 0 ? 'Prendas con stock bajo o agotadas' : 'Inventario en niveles óptimos')
            ->descriptionIcon('heroicon-m-exclamation-triangle')
            ->color($alertasStock > 0 ? 'danger' : 'success')
            ->url(LowStockReport::getUrl());

        if (!$isAlmacenero) {
            $stats[] = Stat::make('Caja diaria', $cajaLabel)
                ->description($cajaDesc)
                ->descriptionIcon($cajaAbierta ? 'heroicon-m-lock-open' : 'heroicon-m-lock-closed')
                ->color($cajaColor);
        }

        $stats[] = Stat::make('Traspasos en ruta', $traspasosEnRuta)
            ->description("{$traspasosUnidades} prendas en tránsito")
            ->descriptionIcon('heroicon-m-truck')
            ->color($traspasosEnRuta > 0 ? 'warning' : 'gray');

        return $stats;
    }
}
