<?php

namespace App\Filament\Widgets;

use App\Services\DashboardMetrics;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\Widget;

/**
 * Tabla compacta "Requiere atención" (una fila por tipo y solo si hay casos).
 * Visible para Administrador y Contador (quien solo ve SUNAT y créditos vencidos).
 */
class NeedsAttentionWidget extends Widget
{
    use InteractsWithPageFilters;

    protected static string $view = 'filament.widgets.needs-attention';
    protected int | string | array $columnSpan = 'full';
    protected static ?int $sort = 2;

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

    public function getViewData(): array
    {
        $user = auth()->user();
        $isContador = $user?->hasRole('contador')
            && !$user?->seesAllBranches()
            && !$user?->hasRole(['super_admin', 'administrador']);

        $branchId = $this->getBranchId();
        $items = DashboardMetrics::attentionItems($branchId, $isContador);

        return [
            'items' => $items,
        ];
    }
}
