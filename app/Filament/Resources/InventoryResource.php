<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InventoryResource\Pages;
use App\Models\CompanySetting;
use App\Models\Inventory;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Services\StockService;
use Filament\Forms;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Inventario por prenda (modelo / color / talla) y almacén.
 * El stock nunca se escribe a mano: entra por compras y traspasos, sale por ventas, y se corrige
 * con "Ajustar stock", que deja el movimiento en el kardex (AGENTS.md).
 */
class InventoryResource extends Resource
{
    protected static ?string $model = Inventory::class;
    protected static ?string $navigationIcon = 'heroicon-o-archive-box';
    protected static ?string $navigationGroup = '2. Catálogo e Inventario';
    protected static ?int $navigationSort = 2;
    protected static ?string $modelLabel = 'Inventario';
    protected static ?string $pluralModelLabel = 'Inventario';
    protected static ?string $navigationLabel = 'Inventario';

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->lowStock()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Tallas y colores con stock bajo';
    }

    public static function canCreate(): bool
    {
        return false; // Las filas se crean solas al crear variantes; el stock entra por compras o ajustes
    }

    public static function table(Table $table): Table
    {
        $seesAll = auth()->user()?->seesAllBranches() ?? false;

        return $table
            ->defaultSort('stock')
            ->columns([
                Tables\Columns\TextColumn::make('branch.name')->label('Tienda')->sortable()->visible($seesAll)->toggleable(),
                Tables\Columns\TextColumn::make('warehouse.name')->label('Almacén')->sortable()->weight('semibold'),
                Tables\Columns\TextColumn::make('productVariant.product.name')->label('Modelo')->searchable()->sortable()->wrap(),
                Tables\Columns\TextColumn::make('productVariant.color_name')->label('Color')->searchable(),
                Tables\Columns\TextColumn::make('productVariant.size')->label('Talla')->searchable(),
                Tables\Columns\TextColumn::make('productVariant.sku')->label('SKU')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('productVariant.barcode')->label('Código de barras')->searchable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('stock')
                    ->label('Stock')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color(fn (Inventory $record) => $record->stock <= 0 ? 'danger' : ($record->is_low_stock ? 'warning' : 'success'))
                    ->description(fn (Inventory $record) => $record->is_low_stock ? 'Bajo (≤ ' . $record->low_stock_threshold . ')' : null),
            ])
            ->filters([
                Tables\Filters\Filter::make('low_stock')
                    ->label('Solo stock bajo')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->lowStock()),
                Tables\Filters\SelectFilter::make('branch_id')
                    ->relationship('branch', 'name')->label('Tienda')->preload()->visible($seesAll),
                Tables\Filters\SelectFilter::make('warehouse_id')
                    ->relationship('warehouse', 'name', function (Builder $query) {
                        if (!auth()->user()?->seesAllBranches()) {
                            $query->where('branch_id', auth()->user()?->branch_id ?? 1);
                        }

                        return $query->where('is_active', true);
                    })
                    ->label('Almacén')->preload(),
                Tables\Filters\SelectFilter::make('season')
                    ->label('Temporada')
                    ->options(fn () => \App\Models\Season::orderByDesc('id')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn ($q, $season) => $q->whereHas('productVariant.product', fn ($p) => $p->where('season_id', $season)))),
            ])
            ->headerActions([
                static::adjustAction('ingresar_stock', 'Ingresar / ajustar stock', null),
            ])
            ->actions([
                static::adjustAction('ajustar', 'Ajustar', 'record'),
                Tables\Actions\Action::make('kardex')
                    ->label('Kardex')
                    ->icon('heroicon-o-list-bullet')
                    ->color('gray')
                    ->url(fn (Inventory $record) => StockMovementResource::getUrl('index', [
                        'tableFilters' => [
                            'product_variant_id' => ['value' => $record->product_variant_id],
                            'warehouse_id' => ['value' => $record->warehouse_id],
                        ],
                    ])),
            ])
            ->bulkActions([]);
    }

    /**
     * Ajuste de inventario: fija el stock contado o suma un ingreso, siempre por el kardex.
     *
     * @param  string|null  $mode  'record' para la fila; null para elegir prenda y almacén.
     */
    protected static function adjustAction(string $name, string $label, ?string $mode): Tables\Actions\Action
    {
        $action = $mode === 'record' ? Tables\Actions\Action::make($name) : Tables\Actions\Action::make($name);

        return $action
            ->label($label)
            ->icon('heroicon-o-adjustments-horizontal')
            ->color($mode === 'record' ? 'warning' : 'primary')
            ->visible(fn () => auth()->user()?->can('update_inventory'))
            ->modalHeading('Ajuste de inventario')
            ->modalDescription('Queda registrado en el kardex con tu usuario y el motivo.')
            ->form(function (?Inventory $record) use ($mode) {
                $fields = [];
                if ($mode !== 'record') {
                    $fields[] = Forms\Components\Select::make('warehouse_id')
                        ->label('Almacén')
                        ->options(fn () => Warehouse::query()
                            ->when(!auth()->user()?->seesAllBranches(), fn ($q) => $q->where('branch_id', auth()->user()?->branch_id ?? 1))
                            ->where('is_active', true)->pluck('name', 'id'))
                        ->default(fn () => Warehouse::where('branch_id', auth()->user()?->branch_id ?? 1)->where('is_default', true)->value('id'))
                        ->required()
                        ->live();
                    $fields[] = Forms\Components\Select::make('product_variant_id')
                        ->label('Prenda')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => ProductVariant::with('product')
                            ->whereHas('product', fn ($p) => $p->where('is_service', false))
                            ->where(fn ($q) => $q->where('barcode', $search)->orWhere('sku', 'like', "%{$search}%")
                                ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%")))
                            ->limit(50)->get()
                            ->mapWithKeys(fn ($v) => [$v->id => "[{$v->sku}] {$v->display_name}"]))
                        ->getOptionLabelUsing(fn ($value) => ($v = ProductVariant::with('product')->find($value)) ? "[{$v->sku}] {$v->display_name}" : null)
                        ->required();
                } else {
                    $fields[] = Forms\Components\Placeholder::make('actual')
                        ->label('Stock actual en el sistema')
                        ->content(fn () => $record ? "{$record->stock} unidades · {$record->productVariant?->display_name}" : '—');
                }

                $fields[] = Forms\Components\ToggleButtons::make('mode')
                    ->label('Tipo de ajuste')
                    ->options(['set' => 'Conteo (fijar cantidad)', 'add' => 'Ingreso (sumar)', 'remove' => 'Salida (restar)'])
                    ->default($mode === 'record' ? 'set' : 'add')
                    ->inline()
                    ->required();
                $fields[] = Forms\Components\TextInput::make('quantity')
                    ->label('Cantidad')
                    ->numeric()->minValue(0)->required();
                $fields[] = Forms\Components\Select::make('reason')
                    ->label('Motivo')
                    ->options([
                        'Stock inicial' => 'Stock inicial',
                        'Conteo físico' => 'Conteo físico',
                        'Prenda dañada' => 'Prenda dañada',
                        'Pérdida o robo' => 'Pérdida o robo',
                        'Devolución a proveedor' => 'Devolución a proveedor',
                        'Corrección' => 'Corrección',
                    ])
                    ->required();
                $fields[] = Forms\Components\TextInput::make('note')->label('Detalle (opcional)')->maxLength(150);

                return $fields;
            })
            ->action(function (array $data, ?Inventory $record) use ($mode) {
                $variantId = $mode === 'record' ? $record->product_variant_id : (int) $data['product_variant_id'];
                $warehouseId = $mode === 'record' ? $record->warehouse_id : (int) $data['warehouse_id'];
                $qty = (int) $data['quantity'];
                $note = trim($data['reason'] . (filled($data['note'] ?? null) ? ': ' . $data['note'] : ''));

                try {
                    $movement = match ($data['mode']) {
                        'set' => StockService::setTo($variantId, $warehouseId, $qty, $note),
                        'add' => $qty > 0 ? StockService::move($variantId, $warehouseId, $qty, $data['reason'] === 'Stock inicial' ? 'initial' : 'adjustment', null, $note) : null,
                        'remove' => $qty > 0 ? StockService::move($variantId, $warehouseId, -$qty, 'adjustment', null, $note) : null,
                    };
                } catch (ValidationException $e) {
                    Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

                    return;
                }

                Notification::make()
                    ->title($movement ? 'Stock actualizado' : 'Sin cambios')
                    ->body($movement ? "Nuevo stock: {$movement->balance_after}" : 'La cantidad era la misma.')
                    ->success()
                    ->send();
            });
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['branch', 'warehouse', 'productVariant.product'])
            ->whereHas('productVariant.product', fn ($q) => $q->where('is_service', false));

        // Quien no ve todas las tiendas solo ve el inventario de la suya
        if (!auth()->user()?->seesAllBranches()) {
            $query->where('inventories.branch_id', auth()->user()?->branch_id ?? 1);
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInventories::route('/'),
        ];
    }
}
