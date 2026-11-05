<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\LowStockReport;
use App\Services\DashboardMetrics;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * Tabla "Reponer primero": máximo 10 variantes con stock <= umbral,
 * ordenadas por días de stock = stock / (unidades vendidas en 30 días / 30).
 * Visible para Administrador y Almacenero.
 */
class RestockPriorityWidget extends Widget
{
    use InteractsWithPageFilters;

    protected static string $view = 'filament.widgets.restock-priority';
    protected int | string | array $columnSpan = 'full';
    protected static ?int $sort = 3;

    public static function canView(): bool
    {
        $user = auth()->user();
        if (!$user) {
            return false;
        }

        return $user->seesAllBranches()
            || $user->hasRole(['super_admin', 'administrador', 'almacenero']);
    }

    protected function getBranchId(): ?int
    {
        $user = auth()->user();
        if (!$user?->seesAllBranches()) {
            return $user?->branch_id ?? 1;
        }

        return !empty($this->filters['branch_id']) ? (int) $this->filters['branch_id'] : null;
    }

    public function getViewData(): array
    {
        $branchId = $this->getBranchId();
        $items = DashboardMetrics::restockPriorityList($branchId);

        $reportUrl = null;
        try {
            $reportUrl = LowStockReport::getUrl();
        } catch (\Throwable) {
            $reportUrl = null;
        }

        return [
            'items' => $items,
            'reportUrl' => $reportUrl,
        ];
    }
}
