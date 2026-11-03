<?php

namespace App\Filament\Resources\InventoryResource\Pages;

use App\Filament\Resources\InventoryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListInventories extends ListRecords
{
    protected static string $resource = InventoryResource::class;

    public function getHeader(): ?\Illuminate\Contracts\Support\Htmlable
    {
        return view('filament.components.inventory-tabs', ['active' => 'list']);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('matriz')
                ->label('Matriz talla × color')
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->url(\App\Filament\Pages\StockMatrix::getUrl()),
            Actions\Action::make('reposicion')
                ->label('Reporte de stock bajo')
                ->icon('heroicon-o-exclamation-triangle')
                ->color('warning')
                ->url(\App\Filament\Pages\LowStockReport::getUrl()),
        ];
    }
}
