<?php

namespace App\Filament\Resources\DespatchResource\Pages;

use App\Filament\Resources\DespatchResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDespatches extends ListRecords
{
    protected static string $resource = DespatchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Nueva Guía de Remisión'),
        ];
    }
}
