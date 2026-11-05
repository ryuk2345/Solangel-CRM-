<?php

namespace App\Filament\Pages;

use App\Exports\KardexExcelExport;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Services\KardexService;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\ColumnGroup;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TextColumn\TextColumnSize;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Artisan;

/**
 * Reporte de Kardex Valorizado y Ficha de Movimientos por Prenda.
 * Implementado 100% con componentes nativos de Filament 3.
 */
class KardexReport extends Page implements HasForms, HasTable
{
    use HasPageShield;
    use InteractsWithForms;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';
    protected static ?string $navigationGroup = 'Administración';
    protected static ?string $navigationLabel = 'Kardex';
    protected static ?string $title = 'Kardex Valorizado';
    protected static ?int $navigationSort = 3;
    protected static string $view = 'filament.pages.kardex-report';

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if (!$user) {
            return false;
        }

        return $user->seesAllBranches()
            || $user->hasRole(['super_admin', 'administrador', 'contador', 'almacenero'])
            || $user->can('view_any_stock::movement')
            || $user->can('page_KardexReport');
    }

    /** Pestaña activa: 'valuation' (por defecto) o 'movements'. */
    public string $activeTab = 'valuation';

    /** ID de variante seleccionada para la ficha de movimientos (Pestaña B). */
    public ?int $selectedVariantId = null;

    /** Datos de los filtros del formulario. */
    public ?array $data = [];

    public function mount(): void
    {
        $defaultWh = auth()->user()?->seesAllBranches()
            ? null
            : Warehouse::where('branch_id', auth()->user()?->branch_id)->value('id');

        $this->form->fill([
            'period' => now()->format('Y-m'),
            'warehouse_id' => $defaultWh,
            'category_id' => null,
            'product_id' => null,
            'only_with_movements' => false,
        ]);

        $firstVariant = ProductVariant::whereHas('product', fn ($q) => $q->where('is_service', false))->value('id');
        $this->selectedVariantId = $firstVariant ? (int) $firstVariant : null;
    }

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resetTable();
    }

    public function openMovements(int $variantId, ?int $warehouseId = null): void
    {
        $this->selectedVariantId = $variantId;
        if ($warehouseId) {
            $this->data['warehouse_id'] = $warehouseId;
        }
        $this->activeTab = 'movements';
        $this->resetTable();
    }

    public function updatedSelectedVariantId(): void
    {
        $this->resetTable();
    }

    public function form(Form $form): Form
    {
        $periods = [];
        $cursor = now()->startOfMonth();
        for ($i = 0; $i < 12; $i++) {
            $val = $cursor->format('Y-m');
            $periods[$val] = ucfirst($cursor->translatedFormat('F Y'));
            $cursor->subMonth();
        }

        return $form->statePath('data')->columns(6)->schema([
            Forms\Components\Select::make('period')
                ->label('Periodo')
                ->options($periods)
                ->default(now()->format('Y-m'))
                ->required()
                ->live()
                ->afterStateUpdated(fn () => $this->resetTable())
                ->columnSpan(2),

            Forms\Components\Select::make('warehouse_id')
                ->label('Almacén')
                ->placeholder('Todos los almacenes')
                ->options(fn () => Warehouse::where('is_active', true)->pluck('name', 'id'))
                ->live()
                ->afterStateUpdated(fn () => $this->resetTable())
                ->columnSpan(2),

            Forms\Components\Select::make('category_id')
                ->label('Categoría')
                ->placeholder('Todas las categorías')
                ->options(fn () => Category::pluck('name', 'id'))
                ->live()
                ->afterStateUpdated(fn () => $this->resetTable())
                ->columnSpan(2),

            Forms\Components\Select::make('product_id')
                ->label('Modelo')
                ->placeholder('Todos los modelos')
                ->searchable()
                ->options(function ($get) {
                    $q = Product::where('is_service', false);
                    if ($cat = $get('category_id')) {
                        $q->where('category_id', $cat);
                    }
                    return $q->pluck('name', 'id');
                })
                ->live()
                ->afterStateUpdated(fn () => $this->resetTable())
                ->columnSpan(3),

            Forms\Components\Checkbox::make('only_with_movements')
                ->label('Solo con movimientos')
                ->inline(false)
                ->live()
                ->afterStateUpdated(fn () => $this->resetTable())
                ->columnSpan(3),
        ]);
    }

    /** Formulario de selección de prenda para la Pestaña B. */
    public function getVariantSelectOptions(): array
    {
        return ProductVariant::with(['product', 'color', 'size'])
            ->whereHas('product', fn ($q) => $q->where('is_service', false))
            ->get()
            ->mapWithKeys(function ($v) {
                $label = "{$v->sku} · {$v->product?->name} (" . ($v->size?->name ?? $v->size ?? 'U') . ' / ' . ($v->color?->name ?? $v->color_name ?? 'Único') . ')';
                return [$v->id => $label];
            })
            ->all();
    }

    /** Única tabla de la página, configurada según la pestaña activa. */
    public function table(Table $table): Table
    {
        if ($this->activeTab === 'movements') {
            return $this->movementsTable($table);
        }

        return $this->valuationTable($table);
    }

    /** Pestaña A: Tabla de Valorización estilo ERP agrupada por Modelo. */
    protected function valuationTable(Table $table): Table
    {
        $dates = $this->getPeriodDates();
        $startOfMonth = $dates['start'];
        $endOfMonth = $dates['end'];
        $warehouseId = $this->data['warehouse_id'] ?? null;
        $categoryId = $this->data['category_id'] ?? null;
        $productId = $this->data['product_id'] ?? null;
        $onlyWithMovements = (bool) ($this->data['only_with_movements'] ?? false);

        $query = ProductVariant::query()
            ->with(['product.category', 'color', 'size'])
            ->whereHas('product', fn ($q) => $q->where('is_service', false));

        if ($productId) {
            $query->where('product_id', $productId);
        }
        if ($categoryId) {
            $query->whereHas('product', fn ($q) => $q->where('category_id', $categoryId));
        }

        // Subconsultas desde stock_movements
        $query->select('product_variants.*');

        // 1. Saldo Inicial
        $query->selectSub(function ($q) use ($startOfMonth, $warehouseId) {
            $q->from('stock_movements')
                ->selectRaw('COALESCE(balance_after, 0)')
                ->whereColumn('stock_movements.product_variant_id', 'product_variants.id')
                ->where('created_at', '<', $startOfMonth)
                ->when($warehouseId, fn ($w) => $w->where('warehouse_id', $warehouseId))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(1);
        }, 'initial_qty');

        $query->selectSub(function ($q) use ($startOfMonth, $warehouseId) {
            $q->from('stock_movements')
                ->selectRaw('COALESCE(balance_total_cost, 0)')
                ->whereColumn('stock_movements.product_variant_id', 'product_variants.id')
                ->where('created_at', '<', $startOfMonth)
                ->when($warehouseId, fn ($w) => $w->where('warehouse_id', $warehouseId))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(1);
        }, 'initial_cost');

        // 2. Entradas
        $query->selectSub(function ($q) use ($startOfMonth, $endOfMonth, $warehouseId) {
            $q->from('stock_movements')
                ->selectRaw("COALESCE(SUM(CASE 
                    WHEN type IN ('purchase', 'initial', 'transfer_in', 'transfer_return') OR (type = 'adjustment' AND quantity > 0) THEN ABS(quantity)
                    WHEN type = 'purchase_void' THEN -ABS(quantity)
                    ELSE 0 END), 0)")
                ->whereColumn('stock_movements.product_variant_id', 'product_variants.id')
                ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                ->when($warehouseId, fn ($w) => $w->where('warehouse_id', $warehouseId));
        }, 'entries_qty');

        $query->selectSub(function ($q) use ($startOfMonth, $endOfMonth, $warehouseId) {
            $q->from('stock_movements')
                ->selectRaw("COALESCE(SUM(CASE 
                    WHEN type IN ('purchase', 'initial', 'transfer_in', 'transfer_return') OR (type = 'adjustment' AND quantity > 0) THEN ABS(total_cost)
                    WHEN type = 'purchase_void' THEN -ABS(total_cost)
                    ELSE 0 END), 0)")
                ->whereColumn('stock_movements.product_variant_id', 'product_variants.id')
                ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                ->when($warehouseId, fn ($w) => $w->where('warehouse_id', $warehouseId));
        }, 'entries_cost');

        // 3. Salidas
        $query->selectSub(function ($q) use ($startOfMonth, $endOfMonth, $warehouseId) {
            $q->from('stock_movements')
                ->selectRaw("COALESCE(SUM(CASE 
                    WHEN type IN ('sale', 'transfer_out') OR (type = 'adjustment' AND quantity < 0) THEN ABS(quantity)
                    WHEN type IN ('sale_void', 'exchange_in') THEN -ABS(quantity)
                    ELSE 0 END), 0)")
                ->whereColumn('stock_movements.product_variant_id', 'product_variants.id')
                ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                ->when($warehouseId, fn ($w) => $w->where('warehouse_id', $warehouseId));
        }, 'exits_qty');

        $query->selectSub(function ($q) use ($startOfMonth, $endOfMonth, $warehouseId) {
            $q->from('stock_movements')
                ->selectRaw("COALESCE(SUM(CASE 
                    WHEN type IN ('sale', 'transfer_out') OR (type = 'adjustment' AND quantity < 0) THEN ABS(total_cost)
                    WHEN type IN ('sale_void', 'exchange_in') THEN -ABS(total_cost)
                    ELSE 0 END), 0)")
                ->whereColumn('stock_movements.product_variant_id', 'product_variants.id')
                ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
                ->when($warehouseId, fn ($w) => $w->where('warehouse_id', $warehouseId));
        }, 'exits_cost');

        // 4. Saldo Final
        $query->selectSub(function ($q) use ($endOfMonth, $warehouseId) {
            $q->from('stock_movements')
                ->selectRaw('COALESCE(balance_after, 0)')
                ->whereColumn('stock_movements.product_variant_id', 'product_variants.id')
                ->where('created_at', '<=', $endOfMonth)
                ->when($warehouseId, fn ($w) => $w->where('warehouse_id', $warehouseId))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(1);
        }, 'final_qty');

        $query->selectSub(function ($q) use ($endOfMonth, $warehouseId) {
            $q->from('stock_movements')
                ->selectRaw('COALESCE(balance_unit_cost, 0)')
                ->whereColumn('stock_movements.product_variant_id', 'product_variants.id')
                ->where('created_at', '<=', $endOfMonth)
                ->when($warehouseId, fn ($w) => $w->where('warehouse_id', $warehouseId))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(1);
        }, 'avg_cost');

        $query->selectSub(function ($q) use ($endOfMonth, $warehouseId) {
            $q->from('stock_movements')
                ->selectRaw('COALESCE(balance_total_cost, 0)')
                ->whereColumn('stock_movements.product_variant_id', 'product_variants.id')
                ->where('created_at', '<=', $endOfMonth)
                ->when($warehouseId, fn ($w) => $w->where('warehouse_id', $warehouseId))
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(1);
        }, 'final_value');

        if ($onlyWithMovements) {
            $query->havingRaw('(COALESCE(initial_qty, 0) > 0 OR COALESCE(entries_qty, 0) > 0 OR COALESCE(exits_qty, 0) > 0 OR COALESCE(final_qty, 0) > 0)');
        }

        return $table
            ->query($query)
            ->defaultGroup(
                Group::make('product.name')
                    ->label('Modelo')
                    ->collapsible()
            )
            ->columns([
                TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable()
                    ->size(TextColumnSize::Small)
                    ->color('gray'),

                TextColumn::make('size_label')
                    ->label('Talla')
                    ->state(fn ($record) => $record->size?->name ?? $record->size ?? 'U')
                    ->badge(),

                TextColumn::make('color_label')
                    ->label('Color')
                    ->state(fn ($record) => $record->color?->name ?? $record->color_name ?? 'Único'),

                TextColumn::make('warehouse_name')
                    ->label('Almacén')
                    ->state(fn () => $warehouseId ? Warehouse::find($warehouseId)?->name : 'Todos')
                    ->hidden(fn () => filled($warehouseId)),

                TextColumn::make('initial_qty')
                    ->label('Saldo inicial')
                    ->numeric(decimalPlaces: 0)
                    ->alignment(Alignment::End)
                    ->summarize(
                        Summarizer::make()
                            ->label('Total')
                            ->numeric(decimalPlaces: 0)
                            ->using(fn (\Illuminate\Database\Query\Builder $query) => (clone $query)->get()->sum(fn ($r) => (int) ($r->initial_qty ?? 0)))
                    ),

                TextColumn::make('entries_qty')
                    ->label('Entradas')
                    ->numeric(decimalPlaces: 0)
                    ->alignment(Alignment::End)
                    ->color('success')
                    ->summarize(
                        Summarizer::make()
                            ->label('Total')
                            ->numeric(decimalPlaces: 0)
                            ->using(fn (\Illuminate\Database\Query\Builder $query) => (clone $query)->get()->sum(fn ($r) => (int) ($r->entries_qty ?? 0)))
                    ),

                TextColumn::make('exits_qty')
                    ->label('Salidas')
                    ->numeric(decimalPlaces: 0)
                    ->alignment(Alignment::End)
                    ->color('danger')
                    ->summarize(
                        Summarizer::make()
                            ->label('Total')
                            ->numeric(decimalPlaces: 0)
                            ->using(fn (\Illuminate\Database\Query\Builder $query) => (clone $query)->get()->sum(fn ($r) => (int) ($r->exits_qty ?? 0)))
                    ),

                TextColumn::make('final_qty')
                    ->label('Stock final')
                    ->numeric(decimalPlaces: 0)
                    ->alignment(Alignment::End)
                    ->summarize(
                        Summarizer::make()
                            ->label('Total')
                            ->numeric(decimalPlaces: 0)
                            ->using(fn (\Illuminate\Database\Query\Builder $query) => (clone $query)->get()->sum(fn ($r) => (int) ($r->final_qty ?? 0)))
                    ),

                TextColumn::make('avg_cost')
                    ->label('Costo promedio')
                    ->numeric(decimalPlaces: 2)
                    ->alignment(Alignment::End)
                    ->visible(fn () => $this->canViewCosts()),

                TextColumn::make('final_value')
                    ->label('Valor final')
                    ->numeric(decimalPlaces: 2)
                    ->alignment(Alignment::End)
                    ->visible(fn () => $this->canViewCosts())
                    ->summarize(
                        Summarizer::make()
                            ->label('Total S/')
                            ->numeric(decimalPlaces: 2)
                            ->using(fn (\Illuminate\Database\Query\Builder $query) => (clone $query)->get()->sum(fn ($r) => (float) ($r->final_value ?? 0)))
                    ),
            ])
            ->actions([
                \Filament\Tables\Actions\Action::make('view_movements')
                    ->label('Ver movimientos')
                    ->icon('heroicon-m-arrows-right-left')
                    ->tooltip('Abrir ficha kardex de esta prenda')
                    ->action(fn ($record) => $this->openMovements($record->id, $warehouseId)),
            ])
            ->emptyStateHeading('No hay existencias para los filtros seleccionados')
            ->emptyStateIcon('heroicon-o-archive-box-x-mark');
    }

    /** Pestaña B: Ficha Kardex con ColumnGroup (Entradas, Salidas y Saldos). */
    protected function movementsTable(Table $table): Table
    {
        $dates = $this->getPeriodDates();
        $startOfMonth = $dates['start'];
        $endOfMonth = $dates['end'];
        $warehouseId = $this->data['warehouse_id'] ?? null;
        $variantId = $this->selectedVariantId;

        $query = StockMovement::query()
            ->when($variantId, fn ($q) => $q->where('product_variant_id', $variantId))
            ->when(!$variantId, fn ($q) => $q->whereRaw('1 = 0'))
            ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc');

        return $table
            ->query($query)
            ->columns([
                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i'),

                TextColumn::make('document')
                    ->label('Documento')
                    ->state(fn ($record) => $record->payment_document ?: ($record->guide_document ?: '—')),

                TextColumn::make('detail')
                    ->label('Detalle')
                    ->state(function ($record) {
                        $note = $record->note ?: $record->type_label;
                        if (in_array($record->type, ['exchange_in', 'sale_void'])) {
                            return "{$note} (Devolución)";
                        }
                        return $note;
                    })
                    ->badge(fn ($record) => in_array($record->type, ['exchange_in', 'sale_void']))
                    ->color(fn ($record) => in_array($record->type, ['exchange_in', 'sale_void']) ? 'warning' : null),

                ColumnGroup::make('ENTRADAS')
                    ->columns([
                        TextColumn::make('entry_qty')
                            ->label('Cant.')
                            ->state(fn ($record) => self::movementEntryQty($record))
                            ->numeric(decimalPlaces: 0)
                            ->alignment(Alignment::End)
                            ->color('success')
                            ->summarize(
                                Summarizer::make()
                                    ->label('Total')
                                    ->numeric(decimalPlaces: 0)
                                    ->using(function (\Illuminate\Database\Query\Builder $query) {
                                        return (clone $query)->get()->sum(function ($m) {
                                            $isNegative = $m->type === 'purchase_void';
                                            $isEntry = in_array($m->type, ['purchase', 'initial', 'transfer_in', 'transfer_return'], true)
                                                || ($m->type === 'adjustment' && $m->quantity > 0)
                                                || $isNegative;
                                            if (!$isEntry) return 0;
                                            return $isNegative ? -abs((int) $m->quantity) : abs((int) $m->quantity);
                                        });
                                    })
                            ),

                        TextColumn::make('entry_unit_cost')
                            ->label('C.U.')
                            ->state(fn ($record) => self::isEntryMovement($record) ? $record->unit_cost : null)
                            ->numeric(decimalPlaces: 2)
                            ->alignment(Alignment::End)
                            ->visible(fn () => $this->canViewCosts()),

                        TextColumn::make('entry_total_cost')
                            ->label('Total')
                            ->state(fn ($record) => self::movementEntryTotal($record))
                            ->numeric(decimalPlaces: 2)
                            ->alignment(Alignment::End)
                            ->color('success')
                            ->visible(fn () => $this->canViewCosts())
                            ->summarize(
                                Summarizer::make()
                                    ->label('Total S/')
                                    ->numeric(decimalPlaces: 2)
                                    ->using(function (\Illuminate\Database\Query\Builder $query) {
                                        return (clone $query)->get()->sum(function ($m) {
                                            $isNegative = $m->type === 'purchase_void';
                                            $isEntry = in_array($m->type, ['purchase', 'initial', 'transfer_in', 'transfer_return'], true)
                                                || ($m->type === 'adjustment' && $m->quantity > 0)
                                                || $isNegative;
                                            if (!$isEntry) return 0;
                                            return $isNegative ? -abs((float) $m->total_cost) : abs((float) $m->total_cost);
                                        });
                                    })
                            ),
                    ]),

                ColumnGroup::make('SALIDAS')
                    ->columns([
                        TextColumn::make('exit_qty')
                            ->label('Cant.')
                            ->state(fn ($record) => self::movementExitQty($record))
                            ->numeric(decimalPlaces: 0)
                            ->alignment(Alignment::End)
                            ->color('danger')
                            ->summarize(
                                Summarizer::make()
                                    ->label('Total')
                                    ->numeric(decimalPlaces: 0)
                                    ->using(function (\Illuminate\Database\Query\Builder $query) {
                                        return (clone $query)->get()->sum(function ($m) {
                                            $isExit = in_array($m->type, ['sale', 'transfer_out'], true)
                                                || ($m->type === 'adjustment' && $m->quantity < 0)
                                                || in_array($m->type, ['sale_void', 'exchange_in'], true);
                                            if (!$isExit) return 0;
                                            $isReturn = in_array($m->type, ['sale_void', 'exchange_in'], true);
                                            return $isReturn ? -abs((int) $m->quantity) : abs((int) $m->quantity);
                                        });
                                    })
                            ),

                        TextColumn::make('exit_unit_cost')
                            ->label('C.U.')
                            ->state(fn ($record) => self::isExitMovement($record) ? $record->unit_cost : null)
                            ->numeric(decimalPlaces: 2)
                            ->alignment(Alignment::End)
                            ->visible(fn () => $this->canViewCosts()),

                        TextColumn::make('exit_total_cost')
                            ->label('Total')
                            ->state(fn ($record) => self::movementExitTotal($record))
                            ->numeric(decimalPlaces: 2)
                            ->alignment(Alignment::End)
                            ->color('danger')
                            ->visible(fn () => $this->canViewCosts())
                            ->summarize(
                                Summarizer::make()
                                    ->label('Total S/')
                                    ->numeric(decimalPlaces: 2)
                                    ->using(function (\Illuminate\Database\Query\Builder $query) {
                                        return (clone $query)->get()->sum(function ($m) {
                                            $isExit = in_array($m->type, ['sale', 'transfer_out'], true)
                                                || ($m->type === 'adjustment' && $m->quantity < 0)
                                                || in_array($m->type, ['sale_void', 'exchange_in'], true);
                                            if (!$isExit) return 0;
                                            $isReturn = in_array($m->type, ['sale_void', 'exchange_in'], true);
                                            return $isReturn ? -abs((float) $m->total_cost) : abs((float) $m->total_cost);
                                        });
                                    })
                            ),
                    ]),

                ColumnGroup::make('SALDO')
                    ->columns([
                        TextColumn::make('balance_after')
                            ->label('Cant.')
                            ->numeric(decimalPlaces: 0)
                            ->alignment(Alignment::End),

                        TextColumn::make('balance_unit_cost')
                            ->label('C.U.')
                            ->numeric(decimalPlaces: 2)
                            ->alignment(Alignment::End)
                            ->visible(fn () => $this->canViewCosts()),

                        TextColumn::make('balance_total_cost')
                            ->label('Total')
                            ->numeric(decimalPlaces: 2)
                            ->alignment(Alignment::End)
                            ->visible(fn () => $this->canViewCosts()),
                    ]),
            ])
            ->emptyStateHeading('No hay movimientos registrados en este periodo para la prenda seleccionada')
            ->emptyStateIcon('heroicon-o-document-text');
    }

    /** Helpers para identificar entradas y salidas */
    public static function isEntryMovement(StockMovement $m): bool
    {
        return in_array($m->type, ['purchase', 'initial', 'transfer_in', 'transfer_return'], true)
            || ($m->type === 'adjustment' && $m->quantity > 0)
            || $m->type === 'purchase_void';
    }

    public static function isExitMovement(StockMovement $m): bool
    {
        return in_array($m->type, ['sale', 'transfer_out'], true)
            || ($m->type === 'adjustment' && $m->quantity < 0)
            || in_array($m->type, ['sale_void', 'exchange_in'], true);
    }

    public static function movementEntryQty(StockMovement $m): ?int
    {
        if (!self::isEntryMovement($m)) {
            return null;
        }
        return $m->type === 'purchase_void' ? -abs((int) $m->quantity) : abs((int) $m->quantity);
    }

    public static function movementEntryTotal(StockMovement $m): ?float
    {
        if (!self::isEntryMovement($m)) {
            return null;
        }
        return $m->type === 'purchase_void' ? -abs((float) $m->total_cost) : abs((float) $m->total_cost);
    }

    public static function movementExitQty(StockMovement $m): ?int
    {
        if (!self::isExitMovement($m)) {
            return null;
        }
        $isReturn = in_array($m->type, ['sale_void', 'exchange_in'], true);
        return $isReturn ? -abs((int) $m->quantity) : abs((int) $m->quantity);
    }

    public static function movementExitTotal(StockMovement $m): ?float
    {
        if (!self::isExitMovement($m)) {
            return null;
        }
        $isReturn = in_array($m->type, ['sale_void', 'exchange_in'], true);
        return $isReturn ? -abs((float) $m->total_cost) : abs((float) $m->total_cost);
    }

    /** Fechas de inicio y fin del periodo actual de filtro */
    public function getPeriodDates(): array
    {
        $raw = !empty($this->data['period']) ? (string) $this->data['period'] : now()->format('Y-m');
        try {
            $date = Carbon::parse($raw)->startOfMonth();
        } catch (\Throwable) {
            $date = now()->startOfMonth();
        }

        return [
            'date' => $date,
            'start' => $date->copy()->startOfMonth(),
            'end' => $date->copy()->endOfMonth(),
            'label' => ucfirst($date->translatedFormat('F Y')),
        ];
    }

    /** Tarjetas estadísticas para el periodo y filtros actuales */
    public function getStats(): array
    {
        $report = KardexService::getReport($this->data ?? []);
        $cons = $report['consolidated'];

        return [
            Stat::make('Inventario inicial', 'S/ ' . number_format($cons['initial_cost'], 2))
                ->description('Al inicio del mes')
                ->color('gray'),

            Stat::make('(+) Compras / Entradas', 'S/ ' . number_format($cons['entries_cost'], 2))
                ->description('Ingresos del periodo')
                ->color('success'),

            Stat::make('(−) Costo de ventas', 'S/ ' . number_format($cons['cost_of_sales'], 2))
                ->description('Salidas valorizadas')
                ->color('danger'),

            Stat::make('Inventario final', 'S/ ' . number_format($cons['final_cost'], 2))
                ->description('Stock al cierre')
                ->color('info'),
        ];
    }

    /** Línea de comprobación contable: S/ inicial + S/ compras − S/ final = S/ costo de ventas */
    public function getComprobacion(): array
    {
        $report = KardexService::getReport($this->data ?? []);
        $cons = $report['consolidated'];
        $diff = (float) $cons['difference'];
        $cuadra = abs($diff) < 0.01;

        $text = 'S/ ' . number_format($cons['initial_cost'], 2)
            . ' + S/ ' . number_format($cons['entries_cost'], 2)
            . ' − S/ ' . number_format($cons['final_cost'], 2)
            . ' = S/ ' . number_format($cons['cost_of_sales'], 2);

        return [
            'text' => $text,
            'cuadra' => $cuadra,
            'diff' => $diff,
            'status' => $cuadra ? '✔ Cuadra' : '✖ Diferencia: S/ ' . number_format($diff, 2),
        ];
    }

    /** Prenda seleccionada para el Infolist de la Ficha Kardex */
    public function getSelectedVariantData(): ?array
    {
        if (!$this->selectedVariantId) {
            return null;
        }

        $variant = ProductVariant::with(['product.category', 'color', 'size'])->find($this->selectedVariantId);
        if (!$variant) {
            return null;
        }

        $dates = $this->getPeriodDates();
        $startOfMonth = $dates['start'];
        $warehouseId = $this->data['warehouse_id'] ?? null;

        // Saldo inicial al 01/mm/aaaa
        $initialMov = StockMovement::where('product_variant_id', $variant->id)
            ->where('created_at', '<', $startOfMonth)
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $initialQty = $initialMov ? (int) $initialMov->balance_after : 0;
        $initialCost = $initialMov ? (float) $initialMov->balance_total_cost : 0.0;
        $initialUnit = $initialQty > 0 ? (float) $initialMov->balance_unit_cost : 0.0;

        // Stock y costo actual
        $lastMov = StockMovement::where('product_variant_id', $variant->id)
            ->where('created_at', '<=', $dates['end'])
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        $currentStock = $lastMov ? (int) $lastMov->balance_after : $initialQty;
        $currentUnit = $lastMov ? (float) $lastMov->balance_unit_cost : $initialUnit;
        $currentTotal = $lastMov ? (float) $lastMov->balance_total_cost : $initialCost;

        $whName = $warehouseId ? (Warehouse::find($warehouseId)?->name ?? 'Almacén') : 'Todos los almacenes';

        return [
            'product_name' => $variant->product?->name ?? 'Prenda',
            'category_name' => $variant->product?->category?->name ?? 'Sin categoría',
            'sku' => $variant->sku,
            'size' => $variant->size?->name ?? $variant->size ?? 'U',
            'color' => $variant->color?->name ?? $variant->color_name ?? 'Único',
            'warehouse' => $whName,
            'initial_date_label' => $startOfMonth->format('d/m/Y'),
            'initial_qty' => $initialQty,
            'initial_unit' => $initialUnit,
            'initial_cost' => $initialCost,
            'current_stock' => $currentStock,
            'current_unit' => $currentUnit,
            'current_total' => $currentTotal,
        ];
    }

    public function getReportProperty(): array
    {
        $filters = $this->data ?? [];
        if (empty($filters['period'])) {
            $filters['period'] = now()->format('Y-m');
        }

        return KardexService::getReport($filters);
    }

    public function canViewCosts(): bool
    {
        $user = auth()->user();
        if (!$user) {
            return false;
        }

        return $user->seesAllBranches()
            || $user->hasRole(['super_admin', 'administrador', 'contador'])
            || $user->can('view_cost_price');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_contador')
                ->label('Exportar Excel (formato contador)')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->action(fn () => KardexExcelExport::download($this->report)),

            Action::make('export_sunat')
                ->label('Exportar formato SUNAT 13.1')
                ->icon('heroicon-o-document-arrow-down')
                ->color('primary')
                ->action(fn () => KardexExcelExport::download($this->report)),

            Action::make('revalorizar')
                ->label('Revalorizar')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn () => auth()->user()?->seesAllBranches() || auth()->user()?->hasRole(['super_admin', 'administrador']))
                ->requiresConfirmation()
                ->modalHeading('Revalorizar Kardex')
                ->modalDescription('Recalcula los costos y documentos de todos los movimientos cronológicamente. ¿Deseas continuar?')
                ->action(function () {
                    Artisan::call('kardex:revalorizar');
                    $this->resetTable();
                    Notification::make()
                        ->title('Kardex revalorizado correctamente')
                        ->success()
                        ->send();
                }),
        ];
    }
}
