<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;
use App\Models\Sale;
use Carbon\Carbon;

class SalesChart extends ChartWidget
{
    protected static ?string $heading = 'Ventas de los últimos 7 días';
    protected static ?int $sort = 2;
    protected static ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return auth()->user()->seesAllBranches() || auth()->user()->can('widget_SalesChart');
    }
    protected function getData(): array
    {
        $user = auth()->user();
        $isSuperAdmin = $user?->seesAllBranches() ?? false;
        $branchId = $user?->branch_id ?? 1;

        $data = [];
        $labels = [];

        for ($i = 6; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            $labels[] = $date->translatedFormat('D d M'); 
            
            $query = Sale::whereDate('created_at', $date)
                ->where('sunat_status', '!=', 'anulado');

            if (!$isSuperAdmin) {
                $query->where('branch_id', $branchId);
            }
                
            $data[] = $query->sum('total');
        }

        return [
            'datasets' => [
                [
                    'label' => 'Total Vendido (S/)',
                    'data' => $data,
                    'fill' => 'start',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.2)',
                    'borderColor' => '#10b981',
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