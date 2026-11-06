<?php

namespace App\Filament\Widgets;

use App\Models\Sale;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class SalesChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?string $heading = 'Ventas de los últimos 7 días';
    protected static ?int $sort = 4;
    protected static ?string $pollingInterval = null;
    protected int | string | array $columnSpan = [
        'default' => 12,
        'lg' => 6,
        'xl' => 6,
    ];

    public static function canView(): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        // Almacenero no ve métricas de ventas
        if ($user->hasRole('almacenero')) {
            return false;
        }

        return $user->seesAllBranches()
            || $user->hasRole(['admin', 'super_admin', 'contador', 'vendedor'])
            || $user->can('widget_SalesChart');
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

    protected function getData(): array
    {
        $user = auth()->user();
        $isVendedor = $user?->hasRole('vendedor');
        $branchId = $this->getBranchId();

        $labels = [];
        $minoristaData = [];
        $mayoristaData = [];

        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            $labels[] = ucfirst($date->locale('es')->translatedFormat('D d M'));

            $dayQuery = Sale::query()
                ->whereDate('issue_date', $date)
                ->whereNotIn('sunat_status', ['anulado', 'rechazado'])
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->when($isVendedor, fn ($q) => $q->where('user_id', $user->id));

            $minorista = (clone $dayQuery)
                ->where(fn ($q) => $q->where('price_mode', 'minorista')->orWhereNull('price_mode'))
                ->sum('total');

            $mayorista = (clone $dayQuery)
                ->where('price_mode', 'mayorista')
                ->sum('total');

            $minoristaData[] = round((float) $minorista, 2);
            $mayoristaData[] = round((float) $mayorista, 2);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Minorista (S/)',
                    'data' => $minoristaData,
                    'borderColor' => '#3b82f6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Mayorista (S/)',
                    'data' => $mayoristaData,
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
