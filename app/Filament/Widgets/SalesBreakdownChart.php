<?php

namespace App\Filament\Widgets;

use App\Services\DashboardMetrics;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Fila 4 (Columna 1): Gráfico de barras con ventas de los últimos 14 días (Minorista vs Mayorista).
 * Solo visible para Administrador y Contador. Sin polling.
 */
class SalesBreakdownChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?string $heading = 'Ventas últimos 14 días';
    protected static ?string $pollingInterval = null;

    protected int | string | array $columnSpan = [
        'default' => 'full',
        'md' => 1,
    ];

    public static function canView(): bool
    {
        $user = auth()->user();
        if (!$user) {
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

    protected function getData(): array
    {
        $branchId = $this->getBranchId();
        $metrics = DashboardMetrics::salesLast14Days($branchId);

        return [
            'datasets' => [
                [
                    'label' => 'Minorista (S/)',
                    'data' => $metrics['retail'],
                    'backgroundColor' => 'rgba(59, 130, 246, 0.75)',
                    'borderColor' => '#2563eb',
                    'borderWidth' => 1,
                ],
                [
                    'label' => 'Mayorista (S/)',
                    'data' => $metrics['wholesale'],
                    'backgroundColor' => 'rgba(16, 185, 129, 0.75)',
                    'borderColor' => '#059669',
                    'borderWidth' => 1,
                ],
            ],
            'labels' => $metrics['labels'],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
