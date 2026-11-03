<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StockMovementResource\Pages;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Kardex: historial de entradas y salidas por prenda y almacén. Solo lectura. */
class StockMovementResource extends Resource
{
    protected static ?string $model = StockMovement::class;
    protected static ?string $navigationGroup = '2. Catálogo e Inventario';
    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';
    protected static ?string $navigationLabel = 'Kardex';
    protected static ?string $modelLabel = 'Movimiento de stock';
    protected static ?string $pluralModelLabel = 'Kardex';
    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('type')->label('Movimiento')->badge()
                    ->formatStateUsing(fn ($state) => StockMovement::TYPES[$state] ?? $state)
                    ->color(fn (StockMovement $record) => $record->quantity >= 0 ? 'success' : 'danger'),
                Tables\Columns\TextColumn::make('productVariant.display_name')->label('Prenda')->wrap()
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('productVariant', fn ($q) => $q
                        ->where('sku', 'like', "%{$search}%")->orWhere('barcode', $search)
                        ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%")))),
                Tables\Columns\TextColumn::make('warehouse.name')->label('Almacén'),
                Tables\Columns\TextColumn::make('quantity')->label('Cantidad')
                    ->formatStateUsing(fn ($state) => ($state > 0 ? '+' : '') . $state)
                    ->color(fn ($state) => $state >= 0 ? 'success' : 'danger')
                    ->weight('bold')
                    ->alignEnd(),
                Tables\Columns\TextColumn::make('balance_after')->label('Saldo')->alignEnd(),
                Tables\Columns\TextColumn::make('note')->label('Referencia / motivo')->wrap(),
                Tables\Columns\TextColumn::make('user.name')->label('Usuario')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->label('Movimiento')->options(StockMovement::TYPES),
                Tables\Filters\SelectFilter::make('warehouse_id')->relationship('warehouse', 'name')->label('Almacén')->preload(),
                Tables\Filters\SelectFilter::make('product_variant_id')
                    ->label('Prenda')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => ProductVariant::with('product')
                        ->where('sku', 'like', "%{$search}%")
                        ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%"))
                        ->limit(50)->get()->mapWithKeys(fn ($v) => [$v->id => "[{$v->sku}] {$v->display_name}"]))
                    ->getOptionLabelUsing(fn ($value) => ($v = ProductVariant::with('product')->find($value)) ? "[{$v->sku}] {$v->display_name}" : null),
                Tables\Filters\Filter::make('fecha')
                    ->form([
                        Forms\Components\DatePicker::make('desde')->label('Desde'),
                        Forms\Components\DatePicker::make('hasta')->label('Hasta'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['desde'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['hasta'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['productVariant.product', 'warehouse', 'user']);

        if (!auth()->user()?->seesAllBranches()) {
            $query->whereHas('warehouse', fn ($w) => $w->where('branch_id', auth()->user()?->branch_id ?? 1));
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStockMovements::route('/'),
        ];
    }
}
