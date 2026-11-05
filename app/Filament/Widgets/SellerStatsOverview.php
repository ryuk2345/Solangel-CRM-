<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\CashRegisterResource;
use App\Models\CashRegister;
use App\Services\DashboardMetrics;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Fila 1 para Vendedor: Mis ventas hoy y Mi caja.
 */
class SellerStatsOverview extends BaseWidget
{
    protected int | string | array $columnSpan = 'full';
    protected static ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        $user = auth()->user();
        if (!$user) {
            return false;
        }

        return $user->hasRole('vendedor')
            && !$user->seesAllBranches()
            && !$user->hasRole(['super_admin', 'administrador']);
    }

    protected function getStats(): array
    {
        $user = auth()->user();
        $userId = $user?->id;
        $branchId = $user?->branch_id ?? 1;

        // 1. Mis ventas hoy (S/ y nº)
        $today = DashboardMetrics::todaySales($branchId, $userId);
        $todayDesc = $today['count'] > 0
            ? "{$today['count']} " . ($today['count'] === 1 ? 'comprobante emitido hoy' : 'comprobantes emitidos hoy')
            : 'Sin ventas registradas hoy';

        $statSales = Stat::make('Mis ventas hoy', 'S/ ' . number_format($today['amount'], 2))
            ->description($todayDesc)
            ->descriptionIcon('heroicon-m-shopping-bag')
            ->color('gray');

        // 2. Mi caja
        $cajaAbierta = CashRegister::where('user_id', $userId)
            ->where('branch_id', $branchId)
            ->where('status', 'abierta')
            ->latest('opened_at')
            ->first();

        $openUrl = null;
        try {
            $openUrl = CashRegisterResource::getUrl('index');
        } catch (\Throwable) {
            $openUrl = null;
        }

        if ($cajaAbierta) {
            $monto = (float) ($cajaAbierta->expected_amount ?: $cajaAbierta->initial_amount);
            $statCaja = Stat::make('Mi caja', 'Abierta · S/ ' . number_format($monto, 2))
                ->description('Turno activo en tu tienda')
                ->descriptionIcon('heroicon-m-lock-open')
                ->color('success');
        } else {
            $statCaja = Stat::make('Mi caja', 'Cerrada')
                ->description('Abrir caja para registrar ventas')
                ->descriptionIcon('heroicon-m-lock-closed')
                ->color('warning');
        }

        if ($openUrl) {
            $statCaja->url($openUrl);
        }

        return [
            $statSales,
            $statCaja,
        ];
    }
}
