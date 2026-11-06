<?php

namespace App\Filament\Widgets;

use App\Models\Sale;
use Carbon\Carbon;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

class PaymentMethodsChart extends ChartWidget
{
    use InteractsWithPageFilters;

    protected static ?string $heading = 'Ingresos por método de pago (este mes)';
    protected static ?int $sort = 5;
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

        // Almacenero no ve métricas de ingresos
        if ($user->hasRole('almacenero')) {
            return false;
        }

        return $user->seesAllBranches()
            || $user->hasRole(['admin', 'super_admin', 'contador', 'vendedor'])
            || $user->can('widget_PaymentMethodsChart');
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

        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        $query = Sale::query()
            ->whereBetween('issue_date', [$startOfMonth, $endOfMonth])
            ->whereNotIn('sunat_status', ['anulado', 'rechazado'])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($isVendedor, fn ($q) => $q->where('user_id', $user->id));

        $sales = (clone $query)
            ->selectRaw('COALESCE(NULLIF(TRIM(payment_method), ""), "Otros") as method, SUM(total) as total')
            ->groupBy('method')
            ->orderByDesc('total')
            ->pluck('total', 'method')
            ->toArray();

        // Si no hay ventas en el mes, mostrar un estado vacío con 0
        if (empty($sales)) {
            $sales = ['Sin ventas' => 0];
        }

        return [
            'datasets' => [
                [
                    'label' => 'Total (S/)',
                    'data' => array_values(array_map('floatval', $sales)),
                    'backgroundColor' => [
                        '#10b981', // Verde
                        '#8b5cf6', // Morado
                        '#3b82f6', // Azul
                        '#f59e0b', // Ámbar
                        '#ec4899', // Rosa
                        '#6b7280', // Gris
                    ],
                ],
            ],
            'labels' => array_map(fn ($k) => ucfirst(strtolower($k)), array_keys($sales)),
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getOptions(): RawJs | array
    {
        return [
            'scales' => [
                'x' => ['display' => false],
                'y' => ['display' => false],
            ],
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'bottom',
                ],
                'tooltip' => [
                    'callbacks' => [
                        'label' => RawJs::make("function(context) { return ' ' + context.label + ': S/ ' + Number(context.raw).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }"),
                    ],
                ],
            ],
        ];
    }
}
