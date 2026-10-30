<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\StatsOverview;

class Dashboard extends \Filament\Pages\Dashboard
{
    // 1. Forzamos el nombre en el menú lateral
    protected static ?string $navigationLabel = 'Dashboard';
    
    // 2. Forzamos el título grande de la pantalla
    protected ?string $heading = 'Dashboard';

    // 3. Le decimos a Filament que traiga de vuelta nuestras tarjetas
    public function getWidgets(): array
    {
        return [
            \App\Filament\Widgets\StatsOverview::class,        // Tus cajas de números
            \App\Filament\Widgets\SalesChart::class,           // El gráfico de líneas
            \App\Filament\Widgets\PaymentMethodsChart::class,  // El gráfico de Dona
            \App\Filament\Widgets\LatestSalesWidget::class,    // La tabla de últimas ventas
        ];
    }
}