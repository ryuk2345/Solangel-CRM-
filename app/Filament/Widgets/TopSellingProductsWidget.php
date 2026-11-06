<?php

namespace App\Filament\Widgets;

use App\Models\SaleItem;
use Carbon\Carbon;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;

class TopSellingProductsWidget extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static ?string $heading = 'Más vendidos del mes';
    protected static ?int $sort = 6;
    protected static ?string $pollingInterval = null;
    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        // Contador no ve la tabla de más vendidos
        if ($user->hasRole('contador')) {
            return false;
        }

        return $user->seesAllBranches()
            || $user->hasRole(['admin', 'super_admin', 'vendedor', 'almacenero'])
            || $user->can('widget_TopSellingProductsWidget');
    }

    protected function getBranchId(): ?int
    {
        $user = auth()->user();
        $canFilter = $user?->seesAllBranches()
            || $user?->hasRole(['admin', 'super_admin']);

        if ($canFilter) {
            return $this->filters['branch_id'] ?? null;
        }

        return $user?->branch_id;
    }

    public function table(Table $table): Table
    {
        $user = auth()->user();
        $isVendedor = $user?->hasRole('vendedor');
        $isAlmacenero = $user?->hasRole('almacenero');
        $branchId = $this->getBranchId();

        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        $query = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('product_variants', 'product_variants.id', '=', 'sale_items.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin('colors', 'colors.id', '=', 'product_variants.color_id')
            ->leftJoin('sizes', 'sizes.id', '=', 'product_variants.size_id')
            ->whereNotIn('sales.sunat_status', ['anulado', 'rechazado'])
            ->whereBetween('sales.issue_date', [$startOfMonth, $endOfMonth])
            ->when($branchId, fn ($q) => $q->where('sales.branch_id', $branchId))
            ->when($isVendedor, fn ($q) => $q->where('sales.user_id', $user->id))
            ->groupBy(
                'sale_items.product_variant_id',
                'products.name',
                'colors.name',
                'sizes.name',
                'product_variants.size',
                'product_variants.color_name'
            )
            ->selectRaw('
                MIN(sale_items.id) as id,
                sale_items.product_variant_id,
                products.name as product_name,
                COALESCE(colors.name, product_variants.color_name, "Único") as color_name,
                COALESCE(sizes.name, product_variants.size, "Única") as size_name,
                SUM(sale_items.quantity) as units_sold,
                SUM(sale_items.total) as total_amount
            ')
            ->orderByDesc('units_sold')
            ->limit(10);

        return $table
            ->query($query)
            ->columns([
                Tables\Columns\TextColumn::make('product_name')
                    ->label('Modelo')
                    ->weight('bold')
                    ->searchable(false)
                    ->sortable(false),

                Tables\Columns\TextColumn::make('color_name')
                    ->label('Color')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('size_name')
                    ->label('Talla')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('units_sold')
                    ->label('Unidades')
                    ->numeric()
                    ->alignEnd()
                    ->badge()
                    ->color('success'),

                Tables\Columns\TextColumn::make('total_amount')
                    ->label('Total (S/)')
                    ->formatStateUsing(fn ($state) => 'S/ ' . number_format((float) $state, 2))
                    ->weight('bold')
                    ->color('success')
                    ->alignEnd()
                    ->visible(! $isAlmacenero),
            ])
            ->paginated(false)
            ->emptyStateHeading('Sin ventas este mes')
            ->emptyStateDescription('Aún no se han registrado ventas de prendas en este periodo.');
    }
}
