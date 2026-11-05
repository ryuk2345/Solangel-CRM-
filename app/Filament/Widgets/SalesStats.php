<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\CashRegisterResource;
use App\Filament\Resources\SaleResource;
use App\Services\DashboardMetrics;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SalesStats extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static ?int $sort = 2;
    protected static ?string $pollingInterval = null;

    public static function canView(): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        // Almacenero no ve métricas de ventas ni dinero
        if ($user->hasRole('almacenero')) {
            return false;
        }

        return $user->seesAllBranches()
            || $user->hasRole(['admin', 'super_admin', 'contador', 'vendedor'])
            || $user->can('widget_SalesStats');
    }

    public function getColumnSpan(): int | string | array
    {
        $user = auth()->user();

        // Para el contador ocupa las 12 columnas (fila completa)
        if ($user?->hasRole('contador')) {
            return 'full';
        }

        // Para el vendedor: 2 tarjetas en 8 columnas, dejando 4 para "Mis ventas por aprobar"
        if ($user?->hasRole('vendedor')) {
            return [
                'default' => 12,
                'lg' => 8,
                'xl' => 8,
            ];
        }

        // Para el administrador: 3 tarjetas en 9 columnas, dejando 3 para "Ventas por aprobar"
        return [
            'default' => 12,
            'lg' => 9,
            'xl' => 9,
        ];
    }

    protected function getColumns(): int | string | array
    {
        $user = auth()->user();
        if ($user?->hasRole('vendedor')) {
            return 2;
        }

        return 3;
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

        // Ventas de hoy
        $todayData = DashboardMetrics::todaySales(
            $branchId,
            $isVendedor ? $user->id : null,
        );

        $todayCount = $todayData['count'];
        $todayDesc = "{$todayCount} " . ($todayCount === 1 ? 'comprobante' : 'comprobantes');

        $stats = [
            Stat::make('Ventas de hoy', 'S/ ' . number_format($todayData['amount'], 2))
                ->description($todayDesc)
                ->descriptionIcon('heroicon-m-arrow-trending-up')
                ->color('success')
                ->url(SaleResource::getUrl('index')),
        ];

        // Ventas del mes (solo Admin y Contador; Vendedor no ve el acumulado global)
        if (! $isVendedor) {
            $monthData = DashboardMetrics::monthSales($branchId);
            $monthName = now()->locale('es')->translatedFormat('F');

            $stats[] = Stat::make('Ventas del mes', 'S/ ' . number_format($monthData['amount'], 2))
                ->description('Acumulado de ' . $monthName)
                ->descriptionIcon('heroicon-m-chart-bar')
                ->color('info')
                ->url(SaleResource::getUrl('index'));
        }

        // Cajas abiertas
        $openRegisters = DashboardMetrics::openCashRegistersCount($branchId);
        $stats[] = Stat::make('Cajas abiertas', (string) $openRegisters)
            ->description($openRegisters > 0 ? 'Hay turnos activos' : 'Sin turnos activos')
            ->descriptionIcon($openRegisters > 0 ? 'heroicon-m-lock-open' : 'heroicon-m-lock-closed')
            ->color($openRegisters > 0 ? 'success' : 'gray')
            ->url(CashRegisterResource::getUrl('index'));

        return $stats;
    }
}
