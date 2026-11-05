<?php

namespace App\Filament\Pages;

use App\Models\Branch;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static ?string $navigationIcon = 'heroicon-o-home';
    protected static ?string $navigationGroup = 'Ventas y cajas';
    protected static ?string $navigationLabel = 'Panel';
    protected ?string $heading = 'Panel';
    protected static ?int $navigationSort = 1;

    public static function canView(): bool
    {
        return auth()->user()?->seesAllBranches() || auth()->user()?->can('page_Dashboard');
    }

    public function filtersForm(Form $form): Form
    {
        $user = auth()->user();
        $canFilterBranch = $user?->seesAllBranches()
            || $user?->hasRole(['admin', 'super_admin', 'contador']);

        if (! $canFilterBranch) {
            return $form->schema([]);
        }

        return $form
            ->schema([
                Section::make()
                    ->compact()
                    ->schema([
                        Select::make('branch_id')
                            ->label('Tienda')
                            ->options(Branch::pluck('name', 'id'))
                            ->placeholder('Todas las tiendas')
                            ->default(null),
                    ]),
            ]);
    }

    public function getColumns(): int | string | array
    {
        return 12;
    }

    public function getWidgets(): array
    {
        return [
            // Fila 0: Bienvenida y salir (nativo de Filament en español)
            \Filament\Widgets\AccountWidget::class,

            // Fila 1: Prendas en stock · Alertas de stock · Por cobrar
            \App\Filament\Widgets\InventoryStats::class,

            // Fila 2: Ventas de hoy · Ventas del mes · Cajas abiertas · Ventas por aprobar
            \App\Filament\Widgets\SalesStats::class,
            \App\Filament\Widgets\PendingApprovalsStat::class,

            // Fila 3: Ventas de los últimos 7 días · Ingresos por método de pago
            \App\Filament\Widgets\SalesChart::class,
            \App\Filament\Widgets\PaymentMethodsChart::class,

            // Fila 4: Más vendidos del mes
            \App\Filament\Widgets\TopSellingProductsWidget::class,
        ];
    }
}
