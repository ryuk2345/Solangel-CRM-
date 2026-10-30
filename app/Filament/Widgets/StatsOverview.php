<?php

namespace App\Filament\Widgets;

use App\Models\Product;
use App\Models\Category;
use App\Models\Branch;
use App\Models\Sale;
use App\Models\CashRegister;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends BaseWidget
{
    // Hacemos que el bloque ocupe todo el ancho de la pantalla
    protected int | string | array $columnSpan = 'full';
    protected static ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return auth()->user()->hasRole('super_admin') || auth()->user()->can('widget_StatsOverview');
    }
    protected function getStats(): array
    {
        $user = auth()->user();
        $isSuperAdmin = $user?->hasRole('super_admin') ?? false;
        $branchId = $user?->branch_id ?? 1;

        // Cálculos financieros con filtro por tienda si no es super_admin
        $ventasHoyQuery = Sale::whereDate('created_at', Carbon::today())
            ->where('sunat_status', '!=', 'anulado');

        $ventasMesQuery = Sale::whereYear('created_at', Carbon::now()->year)
            ->whereMonth('created_at', Carbon::now()->month)
            ->where('sunat_status', '!=', 'anulado');

        $cajasQuery = CashRegister::where('status', 'abierta');

        if (!$isSuperAdmin) {
            $ventasHoyQuery->where('branch_id', $branchId);
            $ventasMesQuery->where('branch_id', $branchId);
            $cajasQuery->where('branch_id', $branchId);
        }

        $ventasHoy = $ventasHoyQuery->sum('total');
        $ventasMes = $ventasMesQuery->sum('total');
        $cajasAbiertas = $cajasQuery->count();

        $branchStat = $isSuperAdmin
            ? Stat::make('Sucursales Activas', Branch::where('is_active', true)->count())
                ->description('Puntos de venta en red')
                ->color('warning')
            : Stat::make('Sucursal Asignada', $user?->branch?->name ?? 'Sede Principal')
                ->description('Punto de venta actual')
                ->color('warning');

        $productCount = $isSuperAdmin
            ? Product::count()
            : Product::whereHas('branches', fn($q) => $q->where('branches.id', $branchId))->count();

        $productDesc = $isSuperAdmin ? 'En todo el catálogo' : 'En catálogo de la tienda';

        return [
            // 📦 MÉTRICAS DE INVENTARIO
            Stat::make('Total de Productos', $productCount)
                ->description($productDesc)
                ->descriptionIcon('heroicon-m-cube')
                ->color('gray'),
                
            Stat::make('Categorías', Category::count())
                ->description('Clasificaciones activas')
                ->color('gray'),
                
            $branchStat,

            // 💰 MÉTRICAS FINANCIERAS
            Stat::make('Ventas de Hoy', 'S/ ' . number_format($ventasHoy, 2))
                ->description('Cobrado el día de hoy')
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success'),
            
            Stat::make('Ventas del Mes', 'S/ ' . number_format($ventasMes, 2))
                ->description('Acumulado de ' . Carbon::now()->translatedFormat('F'))
                ->color('info'),

            Stat::make('Cajas Abiertas', $cajasAbiertas)
                ->description($cajasAbiertas > 0 ? 'Turnos activos' : 'Todas las cajas cerradas')
                ->color($cajasAbiertas > 0 ? 'success' : 'danger')
                ->descriptionIcon($cajasAbiertas > 0 ? 'heroicon-m-lock-open' : 'heroicon-m-lock-closed'),
        ];
    }
}