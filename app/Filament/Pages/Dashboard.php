<?php

namespace App\Filament\Pages;

use App\Models\Branch;
use App\Models\Category;
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

        $defaultCategoryId = Category::whereHas('products.variants.inventories', fn ($q) => $q->where('stock', '>', 0))
            ->orderBy('name')
            ->value('id')
            ?: Category::orderBy('name')->value('id');

        return $form
            ->schema([
                Section::make()
                    ->compact()
                    ->schema([
                        Select::make('branch_id')
                            ->label('Tienda')
                            ->options(Branch::pluck('name', 'id'))
                            ->placeholder('Todas las tiendas')
                            ->visible($canFilterBranch)
                            ->default(null),

                        Select::make('category_id')
                            ->label('Categoría (Stock por talla)')
                            ->options(Category::orderBy('name')->pluck('name', 'id'))
                            ->default($defaultCategoryId)
                            ->required(),
                    ])
                    ->columns($canFilterBranch ? 2 : 1),
            ]);
    }

    public function getColumns(): int | string | array
    {
        return 2;
    }

    public function getWidgets(): array
    {
        return [
            \App\Filament\Widgets\StatsOverview::class,
            \App\Filament\Widgets\StockBySizeWidget::class,
            \App\Filament\Widgets\ShopCashWidget::class,
            \App\Filament\Widgets\InTransitTransfersWidget::class,
            \App\Filament\Widgets\SalesBreakdownChart::class,
            \App\Filament\Widgets\TopModelsWidget::class,
            \App\Filament\Widgets\AdminFinancialStats::class,
            \App\Filament\Widgets\LatestSalesWidget::class,
        ];
    }
}
