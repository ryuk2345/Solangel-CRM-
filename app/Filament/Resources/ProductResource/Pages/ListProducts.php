<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProducts extends ListRecords
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('importar_csv')
                ->label('Importar desde CSV/Excel')
                ->color('success')
                ->icon('heroicon-o-arrow-up-tray')
                ->url(fn () => route('import.products.page')),

            Actions\CreateAction::make(),
        ];
    }
}