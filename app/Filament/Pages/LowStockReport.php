<?php

namespace App\Filament\Pages;

use App\Models\Inventory;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reporte de reposición: tallas y colores con stock bajo en cada almacén (decisión 10).
 * Umbral: el del modelo o el general (20).
 */
class LowStockReport extends Page implements HasTable
{
    use HasPageShield;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';
    protected static ?string $navigationGroup = '2. Catálogo e Inventario';
    protected static ?string $navigationLabel = 'Stock bajo';
    protected static ?string $title = 'Stock bajo y reposición';
    protected static ?int $navigationSort = 5;
    protected static string $view = 'filament.pages.table-page';

    protected function baseQuery(): Builder
    {
        return Inventory::query()
            ->lowStock()
            ->with(['warehouse.branch', 'productVariant.product'])
            ->when(!auth()->user()?->seesAllBranches(), fn ($q) => $q->where('inventories.branch_id', auth()->user()?->branch_id ?? 1));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->baseQuery())
            ->defaultSort('stock')
            ->columns([
                Tables\Columns\TextColumn::make('warehouse.branch.name')->label('Tienda')->toggleable(),
                Tables\Columns\TextColumn::make('warehouse.name')->label('Almacén'),
                Tables\Columns\TextColumn::make('productVariant.product.name')->label('Modelo')->wrap()
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('productVariant.product', fn ($p) => $p->where('name', 'like', "%{$search}%"))),
                Tables\Columns\TextColumn::make('productVariant.color_name')->label('Color'),
                Tables\Columns\TextColumn::make('productVariant.size')->label('Talla'),
                Tables\Columns\TextColumn::make('productVariant.sku')->label('SKU')->toggleable(),
                Tables\Columns\TextColumn::make('stock')->label('Stock')->badge()->sortable()
                    ->color(fn ($state) => $state <= 0 ? 'danger' : 'warning'),
                Tables\Columns\TextColumn::make('low_stock_threshold')->label('Umbral'),
                Tables\Columns\TextColumn::make('faltan')->label('Para llegar al umbral')
                    ->state(fn (Inventory $record) => max(0, $record->low_stock_threshold - (int) $record->stock)),
            ])
            ->filters([
                Tables\Filters\Filter::make('agotados')->label('Solo agotados')->toggle()
                    ->query(fn (Builder $query) => $query->where('inventories.stock', '<=', 0)),
                Tables\Filters\SelectFilter::make('warehouse_id')->label('Almacén')
                    ->relationship('warehouse', 'name')->preload(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('exportar')
                    ->label('Exportar a Excel (CSV)')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn () => auth()->user()?->can('export_reports'))
                    ->action(fn () => $this->exportCsv()),
            ])
            ->emptyStateHeading('No hay tallas con stock bajo')
            ->emptyStateIcon('heroicon-o-check-circle');
    }

    public function exportCsv(): StreamedResponse
    {
        $rows = $this->baseQuery()->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 para Excel
            fputcsv($out, ['Tienda', 'Almacén', 'Modelo', 'Color', 'Talla', 'SKU', 'Stock', 'Umbral', 'Para llegar al umbral'], ';');
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r->warehouse?->branch?->name, $r->warehouse?->name, $r->productVariant?->product?->name,
                    $r->productVariant?->color_name, $r->productVariant?->size, $r->productVariant?->sku,
                    $r->stock, $r->low_stock_threshold, max(0, $r->low_stock_threshold - (int) $r->stock),
                ], ';');
            }
            fclose($out);
        }, 'stock-bajo-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
