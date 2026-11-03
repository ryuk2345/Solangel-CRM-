<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\LowStockReport;
use App\Models\Inventory;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/** Dashboard: tallas y colores con stock bajo (las más urgentes primero). */
class LowStockWidget extends BaseWidget
{
    protected static ?int $sort = 3;
    protected int | string | array $columnSpan = 'full';

    public static function canView(): bool
    {
        return auth()->user()?->seesAllBranches() || auth()->user()?->can('widget_LowStockWidget');
    }

    public function table(Table $table): Table
    {
        $count = $this->query()->count();

        return $table
            ->heading("Stock bajo ({$count} tallas y colores)")
            ->description('Stock igual o menor al umbral del modelo (20 por defecto).')
            ->query($this->query())
            ->defaultSort('stock')
            ->paginated([5])
            ->columns([
                Tables\Columns\TextColumn::make('productVariant.display_name')->label('Prenda')->wrap(),
                Tables\Columns\TextColumn::make('warehouse.name')->label('Almacén'),
                Tables\Columns\TextColumn::make('stock')->label('Stock')->badge()
                    ->color(fn ($state) => $state <= 0 ? 'danger' : 'warning'),
            ])
            ->headerActions([
                Tables\Actions\Action::make('ver_todo')->label('Ver reporte')->url(LowStockReport::getUrl())->color('gray'),
            ])
            ->emptyStateHeading('Todo con stock suficiente');
    }

    private function query()
    {
        return Inventory::query()
            ->lowStock()
            ->with(['warehouse', 'productVariant.product'])
            ->when(!auth()->user()?->seesAllBranches(), fn ($q) => $q->where('inventories.branch_id', auth()->user()?->branch_id ?? 1));
    }
}
