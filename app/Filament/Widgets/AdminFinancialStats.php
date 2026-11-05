<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\AccountsReceivable;
use App\Filament\Resources\SaleResource;
use App\Services\DashboardMetrics;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Fila 5: Indicadores financieros y de control.
 * Visible solo para Administrador y Contador (quien solo ve el Margen).
 */
class AdminFinancialStats extends BaseWidget
{
    use InteractsWithPageFilters;

    protected int | string | array $columnSpan = 'full';
    protected static ?string $pollingInterval = null;

    public static function canView(): bool
    {
        $user = auth()->user();
        if (!$user) {
            return false;
        }

        if ($user->hasRole(['vendedor', 'almacenero']) && !$user->seesAllBranches()) {
            return false;
        }

        return $user->seesAllBranches()
            || $user->hasRole(['super_admin', 'administrador', 'contador']);
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
        $isContadorOnly = $user?->hasRole('contador') && !$user?->seesAllBranches() && !$user?->hasRole(['super_admin', 'administrador']);
        $branchId = $this->getBranchId();

        $stats = [];

        // Tarjetas exclusivas del Administrador
        if (!$isContadorOnly) {
            // 1. Por cobrar vencido
            $credit = DashboardMetrics::overdueCredit($branchId);
            $urlCobrar = null;
            try {
                $urlCobrar = AccountsReceivable::getUrl();
            } catch (\Throwable) {
                $urlCobrar = null;
            }

            $statCobrar = Stat::make('Por cobrar vencido', 'S/ ' . number_format($credit['amount'], 2))
                ->description($credit['description'])
                ->descriptionIcon('heroicon-m-clock')
                ->color($credit['color']);

            if ($urlCobrar) {
                $statCobrar->url($urlCobrar);
            }
            $stats[] = $statCobrar;

            // 2. Aprobaciones de precio pendientes
            $pendingApprovals = DashboardMetrics::pendingPriceApprovalsCount($branchId);
            $urlApprovals = null;
            try {
                $urlApprovals = SaleResource::getUrl('index');
            } catch (\Throwable) {
                $urlApprovals = null;
            }

            $statApprovals = Stat::make('Aprobaciones pendientes', $pendingApprovals)
                ->description($pendingApprovals > 0 ? 'Solicitudes mayoristas por revisar' : 'Al día, sin solicitudes pendientes')
                ->descriptionIcon('heroicon-m-check-badge')
                ->color($pendingApprovals > 0 ? 'warning' : 'success');

            if ($urlApprovals) {
                $statApprovals->url($urlApprovals);
            }
            $stats[] = $statApprovals;
        }

        // 3. Margen del mes (sin IGV) - Administrador y Contador
        $margin = DashboardMetrics::monthMargin($branchId);
        $marginColor = $margin['margin_amount'] >= 0 ? 'success' : 'danger';
        $sign = $margin['margin_amount'] >= 0 ? '+' : '';

        $stats[] = Stat::make('Margen del mes', 'S/ ' . number_format($margin['margin_amount'], 2))
            ->description("{$sign}{$margin['margin_percent']}% margen bruto (sin IGV)")
            ->descriptionIcon('heroicon-m-chart-pie')
            ->color($marginColor);

        return $stats;
    }
}
