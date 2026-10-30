<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use App\Models\Sale;

class PaymentMethodsChart extends ChartWidget
{
    protected static ?string $heading = 'Ingresos por Método de Pago (Este Mes)';
    protected static ?int $sort = 3;
    protected static ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return auth()->user()->hasRole('super_admin') || auth()->user()->can('widget_PaymentMethodsChart');
    }
    protected function getData(): array
    {
        $user = auth()->user();
        $isSuperAdmin = $user?->hasRole('super_admin') ?? false;
        $branchId = $user?->branch_id ?? 1;

        $query = Sale::whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->where('sunat_status', '!=', 'anulado');

        if (!$isSuperAdmin) {
            $query->where('branch_id', $branchId);
        }

        $sales = $query->selectRaw('payment_method, SUM(total) as total')
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method')
            ->toArray();

        return [
            'datasets' => [
                [
                    'label' => 'Total (S/)',
                    'data' => array_values($sales),
                    'backgroundColor' => [
                        '#10b981', // Verde
                        '#8b5cf6', // Morado
                        '#3b82f6', // Azul
                        '#f59e0b', // Naranja
                    ],
                ],
            ],
            'labels' => array_map('ucfirst', array_keys($sales)),
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }
}
