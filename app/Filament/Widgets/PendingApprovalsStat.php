<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\WholesalePriceRequestResource;
use App\Models\WholesalePriceRequest;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PendingApprovalsStat extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 3;
    protected static ?string $pollingInterval = '30s';

    public static function canView(): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        // Contador y almacenero no ven ventas por aprobar
        if ($user->hasRole('contador') || $user->hasRole('almacenero')) {
            return false;
        }

        return $user->seesAllBranches()
            || $user->hasRole(['admin', 'super_admin', 'vendedor'])
            || $user->can('widget_PendingApprovalsStat');
    }

    public function getColumnSpan(): int | string | array
    {
        $user = auth()->user();

        // Para vendedor ocupa 4 columnas (hace 12 junto a las 8 de SalesStats)
        if ($user?->hasRole('vendedor')) {
            return [
                'default' => 12,
                'lg' => 4,
                'xl' => 4,
            ];
        }

        // Para administrador ocupa 3 columnas (hace 12 junto a las 9 de SalesStats)
        return [
            'default' => 12,
            'lg' => 3,
            'xl' => 3,
        ];
    }

    protected function getColumns(): int
    {
        return 1;
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

    protected function getStats(): array
    {
        $user = auth()->user();
        $isVendedor = $user?->hasRole('vendedor');
        $branchId = $this->getBranchId();

        if ($isVendedor) {
            $count = WholesalePriceRequest::query()
                ->where('status', 'pendiente')
                ->where('requested_by', $user->id)
                ->count();

            return [
                Stat::make('Mis ventas por aprobar', (string) $count)
                    ->description($count > 0 ? 'Esperando al administrador' : 'Nada pendiente')
                    ->descriptionIcon('heroicon-m-clock')
                    ->color($count > 0 ? 'warning' : 'gray')
                    ->url(WholesalePriceRequestResource::getUrl()),
            ];
        }

        // Administrador
        $query = WholesalePriceRequest::query()->where('status', 'pendiente');
        if ($branchId) {
            $query->where('branch_id', $branchId);
        }
        $count = $query->count();

        return [
            Stat::make('Ventas por aprobar', (string) $count)
                ->description($count > 0 ? 'Esperando tu PIN' : 'Nada pendiente')
                ->descriptionIcon('heroicon-m-key')
                ->color($count > 0 ? 'warning' : 'gray')
                ->url(WholesalePriceRequestResource::getUrl()),
        ];
    }
}
