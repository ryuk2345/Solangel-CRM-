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
        $canFilterBranch = $user?->seesAllBranches() ?? false;

        if (!$canFilterBranch) {
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
        return 1;
    }

    public function getWidgets(): array
    {
        return [
            // Fila 1 (según rol)
            \App\Filament\Widgets\AdminStatsOverview::class,
            \App\Filament\Widgets\ContadorStatsOverview::class,
            \App\Filament\Widgets\SellerStatsOverview::class,

            // Incidencias y reposición urgente
            \App\Filament\Widgets\NeedsAttentionWidget::class,
            \App\Filament\Widgets\RestockPriorityWidget::class,

            // Específicos por rol
            \App\Filament\Widgets\TopModelsWidget::class,
            \App\Filament\Widgets\SellerPriceRequestsWidget::class,
            \App\Filament\Widgets\IncomingTransfersWidget::class,
            \App\Filament\Widgets\LatestSalesWidget::class,
        ];
    }
}
