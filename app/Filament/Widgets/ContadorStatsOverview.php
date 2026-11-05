<?php

namespace App\Filament\Widgets;

use App\Services\DashboardMetrics;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Fila 1 para Contador: 4 Stats contables y financieras.
 */
class ContadorStatsOverview extends BaseWidget
{
    use InteractsWithPageFilters;

    protected int | string | array $columnSpan = 'full';
    protected static ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        $user = auth()->user();
        if (!$user) {
            return false;
        }

        return $user->hasRole('contador')
            && !$user->seesAllBranches()
            && !$user->hasRole(['super_admin', 'administrador']);
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
        $branchId = $this->getBranchId();

        // 1. Ventas del mes
        $month = DashboardMetrics::monthSales($branchId);
        $statMonth = Stat::make('Ventas del mes', 'S/ ' . number_format($month['amount'], 2))
            ->description('Total facturado del mes')
            ->descriptionIcon('heroicon-m-calendar')
            ->color('gray');

        // 2. Margen del mes (S/ y %, "sin IGV")
        $margin = DashboardMetrics::monthMargin($branchId);
        $marginSign = $margin['margin_amount'] >= 0 ? '+' : '';
        $statMargin = Stat::make('Margen del mes', 'S/ ' . number_format($margin['margin_amount'], 2))
            ->description("{$marginSign}{$margin['margin_percent']}% margen bruto (sin IGV)")
            ->descriptionIcon('heroicon-m-chart-pie')
            ->color($margin['margin_amount'] >= 0 ? 'success' : 'danger');

        // 3. Por cobrar
        $receivable = DashboardMetrics::receivableSummary($branchId);
        $statCobrar = Stat::make('Por cobrar', 'S/ ' . number_format($receivable['total'], 2));
        if ($receivable['overdue'] > 0) {
            $statCobrar->description('S/ ' . number_format($receivable['overdue'], 2) . ' vencido')
                ->descriptionIcon('heroicon-m-exclamation-circle')
                ->color('danger');
        } else {
            $statCobrar->description('Al día, sin créditos vencidos')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('gray');
        }

        // 4. Inventario valorizado (S/, del kardex)
        $valued = DashboardMetrics::valuedInventoryTotal($branchId);
        $statInventario = Stat::make('Inventario valorizado', 'S/ ' . number_format($valued, 2))
            ->description('Saldo valorizado del Kardex')
            ->descriptionIcon('heroicon-m-cube')
            ->color('gray');

        return [
            $statMonth,
            $statMargin,
            $statCobrar,
            $statInventario,
        ];
    }
}
