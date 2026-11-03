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
            Actions\Action::make('print_labels')
                ->label('Imprimir etiquetas')
                ->icon('heroicon-o-qr-code')
                ->color('gray')
                ->url(fn () => \App\Filament\Pages\PrintLabels::getUrl())
                ->visible(fn () => auth()->user()?->can('print_labels') ?? false),
            Actions\CreateAction::make(),
        ];
    }
}