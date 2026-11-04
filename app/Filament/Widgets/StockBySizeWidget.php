<?php

namespace App\Filament\Widgets;

use App\Models\Category;
use App\Models\CompanySetting;
use App\Models\Product;
use App\Models\Size;
use App\Models\Warehouse;
use Filament\Widgets\Widget;

class StockBySizeWidget extends Widget
{
    protected static string $view = 'filament.widgets.stock-by-size';
    protected int | string | array $columnSpan = 'full';
    protected static ?int $sort = 2;

    public ?int $category_id = null;
    public ?int $warehouse_id = null;

    public function mount(): void
    {
        $this->category_id = Category::orderBy('name')->value('id');
        $this->warehouse_id = Warehouse::where('branch_id', auth()->user()?->branch_id ?? 1)->where('is_default', true)->value('id')
            ?: Warehouse::value('id');
    }

    public static function canView(): bool
    {
        return auth()->user()?->seesAllBranches() || auth()->user()?->can('page_Dashboard');
    }

    public function getViewData(): array
    {
        $categories = Category::orderBy('name')->pluck('name', 'id')->all();
        $warehouses = Warehouse::where('is_active', true)->pluck('name', 'id')->all();

        $defaultThreshold = (int) (CompanySetting::value('low_stock_threshold') ?: 5);

        // Obtener tallas ordenadas
        $sizes = Size::orderBy('sort_order')->pluck('name', 'id')->all();
        if (empty($sizes)) {
            $sizes = [1 => 'XS', 2 => 'S', 3 => 'M', 4 => 'L', 5 => 'XL', 6 => 'XXL'];
        }

        $products = Product::where('is_service', false)
            ->when($this->category_id, fn ($q) => $q->where('category_id', $this->category_id))
            ->with(['variants.inventories', 'variants.color', 'variants.sizeOption'])
            ->get();

        $matrixRows = [];
        foreach ($products as $product) {
            $threshold = (int) ($product->low_stock_threshold ?: $defaultThreshold);

            // Agrupar variantes por color
            $byColor = $product->variants->groupBy(fn ($v) => $v->color?->name ?? ($v->color_name ?: 'Único'));

            foreach ($byColor as $colorName => $variants) {
                $sizeStock = [];
                $hasAnyStock = false;
                foreach ($sizes as $sizeId => $sizeName) {
                    $variant = $variants->first(fn ($v) => $v->size_id == $sizeId || strcasecmp((string) $v->size, (string) $sizeName) === 0);
                    if ($variant) {
                        $stock = (int) $variant->inventories
                            ->when($this->warehouse_id, fn ($invs) => $invs->where('warehouse_id', $this->warehouse_id))
                            ->sum('stock');
                        $status = $stock <= 0 ? 'agotado' : ($stock <= $threshold ? 'bajo' : 'normal');
                        $sizeStock[$sizeName] = [
                            'stock' => $stock,
                            'status' => $status,
                        ];
                        $hasAnyStock = true;
                    } else {
                        $sizeStock[$sizeName] = null;
                    }
                }

                if ($hasAnyStock) {
                    $matrixRows[] = [
                        'model' => $product->name,
                        'color' => $colorName,
                        'sizes' => $sizeStock,
                        'retail_price' => (float) $product->sale_price,
                        'wholesale_price' => (float) ($product->wholesale_price ?: $product->sale_price),
                    ];
                }
            }
        }

        return [
            'categories' => $categories,
            'warehouses' => $warehouses,
            'sizes' => array_values($sizes),
            'rows' => $matrixRows,
        ];
    }
}
