<?php

namespace App\Filament\Widgets;

use App\Models\Category;
use App\Models\CompanySetting;
use App\Models\ProductVariant;
use App\Models\Size;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Fila 2: Stock por talla y color en tiempo real.
 * Implementado 100% como TableWidget nativo de Filament 3.
 */
class StockBySizeWidget extends BaseWidget
{
    use InteractsWithPageFilters;

    protected int | string | array $columnSpan = 'full';
    protected static ?string $heading = 'Stock por talla';
    protected static ?int $sort = 2;

    public static function canView(): bool
    {
        return auth()->user()?->seesAllBranches() || auth()->user()?->can('page_Dashboard');
    }

    protected function getBranchId(): ?int
    {
        $user = auth()->user();
        if (!$user?->seesAllBranches()) {
            return $user?->branch_id ?? 1;
        }

        return !empty($this->filters['branch_id']) ? (int) $this->filters['branch_id'] : null;
    }

    protected function getCategoryId(): ?int
    {
        if (!empty($this->filters['category_id'])) {
            return (int) $this->filters['category_id'];
        }

        return Category::whereHas('products.variants.inventories', fn ($q) => $q->where('stock', '>', 0))
            ->orderBy('name')
            ->value('id')
            ?: Category::orderBy('name')->value('id');
    }

    protected function getCategorySizes(?int $categoryId): array
    {
        if (!$categoryId) {
            return Size::ordered()->take(6)->get()->all();
        }

        $sizes = Size::whereHas('variants.product', fn ($q) => $q->where('category_id', $categoryId)->where('is_service', false))
            ->ordered()
            ->get();

        if ($sizes->isEmpty()) {
            $sizes = Size::ordered()->take(6)->get();
        }

        return $sizes->all();
    }

    public function table(Table $table): Table
    {
        $categoryId = $this->getCategoryId();
        $branchId = $this->getBranchId();
        $globalThreshold = CompanySetting::lowStockThreshold();
        $sizes = $this->getCategorySizes($categoryId);

        $query = ProductVariant::query()
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin('inventories', function ($join) use ($branchId) {
                $join->on('inventories.product_variant_id', '=', 'product_variants.id');
                if ($branchId) {
                    $join->where('inventories.branch_id', '=', $branchId);
                }
            })
            ->where('products.is_service', false)
            ->when($categoryId, fn ($q) => $q->where('products.category_id', $categoryId))
            ->groupBy(
                'product_variants.product_id',
                'products.name',
                'product_variants.color_id',
                'product_variants.color_name',
                'products.sale_price',
                'products.wholesale_price',
                'products.low_stock_threshold',
            );

        $selects = [
            DB::raw('MIN(product_variants.id) as id'),
            'product_variants.product_id',
            DB::raw('products.name as product_name'),
            DB::raw("COALESCE(product_variants.color_name, 'Único') as color_name"),
            DB::raw('products.sale_price as retail_price'),
            DB::raw('COALESCE(products.wholesale_price, products.sale_price) as wholesale_price'),
            DB::raw("COALESCE(products.low_stock_threshold, {$globalThreshold}) as threshold"),
        ];

        foreach ($sizes as $size) {
            $selects[] = DB::raw("MAX(CASE WHEN product_variants.size_id = {$size->id} THEN 1 ELSE 0 END) as has_size_{$size->id}");
            $selects[] = DB::raw("SUM(CASE WHEN product_variants.size_id = {$size->id} THEN COALESCE(inventories.stock, 0) ELSE 0 END) as size_{$size->id}");
        }

        $query->select($selects);

        // Columnas nativas de Filament
        $columns = [
            Tables\Columns\TextColumn::make('product_name')
                ->label('Modelo')
                ->weight('bold')
                ->sortable()
                ->searchable(query: fn (Builder $q, string $search) => $q->where('products.name', 'like', "%{$search}%")),

            Tables\Columns\TextColumn::make('color_name')
                ->label('Color')
                ->badge()
                ->color('gray'),
        ];

        foreach ($sizes as $size) {
            $colName = 'size_' . $size->id;
            $columns[] = Tables\Columns\TextColumn::make($colName)
                ->label($size->name)
                ->alignCenter()
                ->badge()
                ->color(function ($state, $record) use ($size) {
                    $hasKey = "has_size_{$size->id}";
                    if (!($record->$hasKey ?? false)) {
                        return 'gray';
                    }
                    $val = (int) $state;
                    if ($val === 0) {
                        return 'danger';
                    }
                    if ($val <= (int) ($record->threshold ?? 20)) {
                        return 'warning';
                    }
                    return 'gray';
                })
                ->formatStateUsing(function ($state, $record) use ($size) {
                    $hasKey = "has_size_{$size->id}";
                    if (!($record->$hasKey ?? false)) {
                        return '—';
                    }
                    return (string) (int) $state;
                });
        }

        $columns[] = Tables\Columns\TextColumn::make('retail_price')
            ->label('Precio minorista')
            ->money('PEN')
            ->alignEnd();

        $columns[] = Tables\Columns\TextColumn::make('wholesale_price')
            ->label('Precio mayorista')
            ->money('PEN')
            ->alignEnd();

        return $table
            ->query($query)
            ->columns($columns)
            ->defaultSort('products.name')
            ->defaultPaginationPageOption(10)
            ->paginated([10])
            ->emptyStateHeading('No hay modelos en esta categoría');
    }
}
