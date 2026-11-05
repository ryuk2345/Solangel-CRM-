<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\LowStockReport;
use App\Services\DashboardMetrics;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Fila 1: Indicadores clave de desempeño del día (polling 60s).
 */
class StatsOverview extends BaseWidget
{
    use InteractsWithPageFilters;

    protected int | string | array $columnSpan = 'full';
    protected static ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        return auth()->user()?->seesAllBranches() || auth()->user()?->can('widget_StatsOverview') || auth()->user()?->can('page_Dashboard');
    }

    protected function getBranchId(): ?int
    {
        $user = auth()->user();
        if (!$user?->seesAllBranches()) {
            return $user?->branch_id ?? 1;
        }

        return !empty($this->filters['branch_id']) ? (int) $this->filters['branch_id'] : null;
    }

    protected function getStats(): array
    {
        $user = auth()->user();
        $isAlmacenero = $user?->hasRole('almacenero') ?? false;
        $branchId = $this->getBranchId();

        $ventasHoy = DashboardMetrics::todaySales($branchId);
        $comparacion = DashboardMetrics::todaySalesComparison($branchId);
        $unidadesHoy = DashboardMetrics::todayUnitsSold($branchId);
        $variantesActivas = DashboardMetrics::activeVariantsCount();
        $alertasStock = DashboardMetrics::stockAlertsCount($branchId);

        $stats = [];

        // 1 y 2: Ventas y unidades de hoy (oculto para almacenero)
        if (!$isAlmacenero) {
            $trendIcon = match ($comparacion['trend']) {
                'up' => 'heroicon-m-arrow-trending-up',
                'down' => 'heroicon-m-arrow-trending-down',
                default => 'heroicon-m-minus',
            };

            $trendColor = match ($comparacion['trend']) {
                'up' => 'success',
                'down' => 'danger',
                default => 'gray',
            };

            $stats[] = Stat::make('Ventas de hoy', 'S/ ' . number_format($ventasHoy, 2))
                ->description($comparacion['label'])
                ->descriptionIcon($trendIcon)
                ->color($trendColor);

            $stats[] = Stat::make('Unidades vendidas hoy', $unidadesHoy)
                ->description('Prendas netas entregadas hoy')
                ->descriptionIcon('heroicon-m-shopping-bag')
                ->color('primary');
        }

        // 3. Variantes activas
        $stats[] = Stat::make('Variantes activas', $variantesActivas)
            ->description('Modelos, tallas y colores')
            ->descriptionIcon('heroicon-m-tag')
            ->color('gray');

        // 4. Alertas de stock bajo
        $stats[] = Stat::make('Alertas de stock', $alertasStock)
            ->description($alertasStock > 0 ? 'Prendas con stock bajo o agotadas' : 'Inventario en niveles óptimos')
            ->descriptionIcon('heroicon-m-exclamation-triangle')
            ->color($alertasStock > 0 ? 'danger' : 'success')
            ->url(LowStockReport::getUrl());

        return $stats;
    }
}
