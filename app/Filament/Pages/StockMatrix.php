<?php

namespace App\Filament\Pages;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\Warehouse;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

/** Matriz de stock talla × color de un modelo, por almacén (o todos los de la tienda). */
class StockMatrix extends Page implements HasForms
{
    use HasPageShield;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-table-cells';
    protected static ?string $navigationGroup = 'Catálogo e inventario';
    protected static ?string $navigationLabel = 'Inventario por talla';
    protected static ?string $title = 'Inventario por talla';
    protected static ?int $navigationSort = 2;
    protected static string $view = 'filament.pages.stock-matrix';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'warehouse_id' => Warehouse::where('branch_id', auth()->user()?->branch_id ?? 1)->where('is_default', true)->value('id'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->columns(3)
            ->schema([
                Forms\Components\Select::make('product_id')
                    ->label('Modelo')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Product::where('is_service', false)
                        ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"))
                        ->limit(50)->get()->mapWithKeys(fn ($p) => [$p->id => trim("[{$p->code}] {$p->name}")]))
                    ->getOptionLabelUsing(fn ($value) => ($p = Product::find($value)) ? trim("[{$p->code}] {$p->name}") : null)
                    ->live()
                    ->columnSpan(2),
                Forms\Components\Select::make('warehouse_id')
                    ->label('Almacén')
                    ->placeholder('Todos los almacenes')
                    ->options(fn () => Warehouse::query()
                        ->when(!auth()->user()?->seesAllBranches(), fn ($q) => $q->where('branch_id', auth()->user()?->branch_id ?? 1))
                        ->where('is_active', true)
                        ->with('branch')->get()
                        ->mapWithKeys(fn ($w) => [$w->id => ($w->branch?->name ? $w->branch->name . ' · ' : '') . $w->name]))
                    ->live(),
            ]);
    }

    /** @return array{product: ?Product, sizes: array, rows: array, totals: array, total: int, threshold: int} */
    public function getMatrix(): array
    {
        $product = !empty($this->data['product_id']) ? Product::with(['variants.color', 'variants.sizeOption'])->find($this->data['product_id']) : null;
        if (!$product) {
            return ['product' => null, 'sizes' => [], 'rows' => [], 'totals' => [], 'total' => 0, 'threshold' => 0];
        }

        $warehouseId = $this->data['warehouse_id'] ?? null;
        $allowedWarehouses = auth()->user()?->seesAllBranches()
            ? null
            : Warehouse::where('branch_id', auth()->user()?->branch_id ?? 1)->pluck('id');

        $stock = Inventory::query()
            ->whereIn('product_variant_id', $product->variants->pluck('id'))
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($allowedWarehouses, fn ($q) => $q->whereIn('warehouse_id', $allowedWarehouses))
            ->selectRaw('product_variant_id, SUM(stock) as qty')
            ->groupBy('product_variant_id')
            ->pluck('qty', 'product_variant_id');

        $variants = $product->variants->sortBy(fn ($v) => [$v->color?->sort_order ?? 999, $v->color_name, $v->sizeOption?->sort_order ?? 999]);
        $sizes = $variants->sortBy(fn ($v) => $v->sizeOption?->sort_order ?? 999)->pluck('size')->unique()->values()->all();

        $rows = [];
        $totals = array_fill_keys($sizes, 0);
        foreach ($variants->groupBy('color_name') as $color => $colorVariants) {
            $cells = [];
            foreach ($sizes as $size) {
                $variant = $colorVariants->firstWhere('size', $size);
                $qty = $variant ? (int) ($stock[$variant->id] ?? 0) : null;
                $cells[$size] = $qty;
                $totals[$size] += (int) $qty;
            }
            $rows[] = [
                'color' => $color ?: 'Único',
                'hex' => $colorVariants->first()?->color_hex ?? $colorVariants->first()?->color?->hex,
                'cells' => $cells,
                'total' => array_sum(array_map('intval', $cells)),
            ];
        }

        return [
            'product' => $product,
            'sizes' => $sizes,
            'rows' => $rows,
            'totals' => $totals,
            'total' => array_sum($totals),
            'threshold' => $product->lowStockThreshold(),
        ];
    }
}
