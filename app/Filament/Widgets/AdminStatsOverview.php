<?php

namespace App\Filament\Widgets;

use App\Services\DashboardMetrics;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Fila 1 para Administrador: 4 Stats operativas y gerenciales.
 */
class AdminStatsOverview extends BaseWidget
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

        return $user->seesAllBranches() || $user->hasRole(['super_admin', 'administrador']);
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

        // 1. Ventas hoy (S/ y nº de comprobantes)
        $today = DashboardMetrics::todaySales($branchId);
        $todayDesc = $today['count'] > 0
            ? "{$today['count']} " . ($today['count'] === 1 ? 'comprobante emitido' : 'comprobantes emitidos')
            : 'Sin ventas registradas hoy';

        $statToday = Stat::make('Ventas hoy', 'S/ ' . number_format($today['amount'], 2))
            ->description($todayDesc)
            ->descriptionIcon('heroicon-m-calendar')
            ->color('gray');

        // 2. Ventas del mes (S/ y comparación vs. mes anterior al mismo día solo si hay base)
        $month = DashboardMetrics::monthSales($branchId);
        $statMonth = Stat::make('Ventas del mes', 'S/ ' . number_format($month['amount'], 2));
        if ($month['comparison_label']) {
            $statMonth->description($month['comparison_label'])
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('gray');
        }

        // 3. Margen del mes (S/ y %, "sin IGV")
        $margin = DashboardMetrics::monthMargin($branchId);
        $marginSign = $margin['margin_amount'] >= 0 ? '+' : '';
        $statMargin = Stat::make('Margen del mes', 'S/ ' . number_format($margin['margin_amount'], 2))
            ->description("{$marginSign}{$margin['margin_percent']}% margen bruto (sin IGV)")
            ->descriptionIcon('heroicon-m-chart-pie')
            ->color($margin['margin_amount'] >= 0 ? 'success' : 'danger');

        // 4. Por cobrar (S/ total; descripción "S/ X vencido" en danger si hay vencido)
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

        return [
            $statToday,
            $statMonth,
            $statMargin,
            $statCobrar,
        ];
    }
}
