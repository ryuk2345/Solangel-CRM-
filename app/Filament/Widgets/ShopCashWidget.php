<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\CashRegisterResource;
use App\Services\DashboardMetrics;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Fila 3 (Columna 1): Caja de la tienda (abierta/cerrada con saldo o descuadre).
 * Oculto para el almacenero.
 */
class ShopCashWidget extends BaseWidget
{
    use InteractsWithPageFilters;

    protected int | string | array $columnSpan = [
        'default' => 'full',
        'md' => 1,
    ];

    protected static ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        $user = auth()->user();
        if (!$user || $user->hasRole('almacenero')) {
            return false;
        }

        return $user->seesAllBranches() || $user->can('page_Dashboard');
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
        $caja = DashboardMetrics::cashRegisterStatus($branchId);

        $url = null;
        try {
            $url = CashRegisterResource::getUrl('index');
        } catch (\Throwable) {
            $url = null;
        }

        $stat = Stat::make('Caja de la tienda', $caja['label'])
            ->description($caja['description'])
            ->descriptionIcon($caja['icon'])
            ->color($caja['color']);

        if ($url) {
            $stat->url($url);
        }

        return [$stat];
    }
}
