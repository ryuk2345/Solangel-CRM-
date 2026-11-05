<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\StockTransferResource;
use App\Services\DashboardMetrics;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Fila 3 (Columna 2): Traspasos en ruta (unidades y destino; clic abre Traspasos).
 */
class InTransitTransfersWidget extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static ?string $pollingInterval = '60s';

    public function getColumnSpan(): int | string | array
    {
        return auth()->user()?->hasRole('almacenero') ? 'full' : [
            'default' => 'full',
            'md' => 1,
        ];
    }

    public static function canView(): bool
    {
        return auth()->user()?->seesAllBranches() || auth()->user()?->can('page_Dashboard');
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
        $transfers = DashboardMetrics::transfersInTransit($branchId);

        $url = null;
        try {
            $url = StockTransferResource::getUrl('index');
        } catch (\Throwable) {
            $url = null;
        }

        $stat = Stat::make('Traspasos en ruta', $transfers['count'] . ' en tránsito')
            ->description($transfers['description'])
            ->descriptionIcon($transfers['icon'])
            ->color($transfers['color']);

        if ($url) {
            $stat->url($url);
        }

        return [$stat];
    }
}
