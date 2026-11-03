<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-home';
    protected static ?string $navigationGroup = 'Ventas y cajas';
    protected static ?string $navigationLabel = 'Panel';
    protected ?string $heading = 'Panel';
    protected static ?int $navigationSort = 1;

    public static function canView(): bool
    {
        // Solo super_admin o usuarios con el permiso específico de la página Dashboard
        return auth()->user()?->seesAllBranches() || auth()->user()?->can('page_Dashboard');
    }

    public function getWidgets(): array
    {
        return [
            \App\Filament\Widgets\StatsOverview::class,
            \App\Filament\Widgets\SalesChart::class,
            \App\Filament\Widgets\PaymentMethodsChart::class,
            \App\Filament\Widgets\LatestSalesWidget::class,
        ];
    }
}
