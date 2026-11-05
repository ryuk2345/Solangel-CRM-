<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\AccountsReceivable;
use App\Filament\Pages\LowStockReport;
use App\Services\DashboardMetrics;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class InventoryStats extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 1;
    protected static ?string $pollingInterval = null;
    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        // Vendedor no ve la Fila 1 de inventario
        if ($user->hasRole('vendedor')) {
            return false;
        }

        return $user->seesAllBranches()
            || $user->hasRole(['admin', 'super_admin', 'contador', 'almacenero'])
            || $user->can('widget_InventoryStats');
    }

    protected function getBranchId(): ?int
    {
        $user = auth()->user();
        $canFilter = $user?->seesAllBranches()
            || $user?->hasRole(['admin', 'super_admin', 'contador']);

        if ($canFilter) {
            return $this->filters['branch_id'] ?? null;
        }

        return $user?->branch_id;
    }

    protected function getColumns(): int
    {
        $user = auth()->user();
        if ($user?->hasRole('almacenero')) {
            return 2;
        }

        return 3;
    }

    protected function getStats(): array
    {
        $branchId = $this->getBranchId();
        $user = auth()->user();

        // 1. Prendas en stock
        $units = DashboardMetrics::stockUnitsCount($branchId);
        $models = DashboardMetrics::modelsCount($branchId);
        $variants = DashboardMetrics::variantsCount($branchId);

        $stats = [
            Stat::make('Prendas en stock', number_format($units) . ' unid.')
                ->description("{$models} modelos · {$variants} variantes")
                ->descriptionIcon('heroicon-m-cube')
                ->color('gray'),
        ];

        // 2. Alertas de stock
        $stockAlerts = DashboardMetrics::stockAlertsCount($branchId);
        $stats[] = Stat::make('Alertas de stock', (string) $stockAlerts)
            ->description($stockAlerts > 0 ? 'Variantes en punto crítico' : 'Stock en niveles óptimos')
            ->descriptionIcon($stockAlerts > 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
            ->color($stockAlerts > 0 ? 'danger' : 'success')
            ->url(LowStockReport::getUrl());

        // 3. Por cobrar (solo Admin y Contador; no Almacenero)
        if (! $user?->hasRole('almacenero')) {
            $receivable = DashboardMetrics::receivableSummary($branchId);
            $hasOverdue = $receivable['overdue'] > 0;

            $stats[] = Stat::make('Por cobrar', 'S/ ' . number_format($receivable['total'], 2))
                ->description($hasOverdue ? 'S/ ' . number_format($receivable['overdue'], 2) . ' vencido' : 'Sin deuda vencida')
                ->descriptionIcon('heroicon-m-banknotes')
                ->color($hasOverdue ? 'danger' : 'gray')
                ->url(AccountsReceivable::getUrl());
        }

        return $stats;
    }
}
