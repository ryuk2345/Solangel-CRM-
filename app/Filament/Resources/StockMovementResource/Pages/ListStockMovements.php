<?php

namespace App\Filament\Resources\StockMovementResource\Pages;

use App\Filament\Resources\StockMovementResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\View\View;

class ListStockMovements extends ListRecords
{
    protected static string $resource = StockMovementResource::class;

    public function getHeader(): ?View
    {
        return view('filament.components.kardex-tabs', ['active' => 'movements']);
    }
}
