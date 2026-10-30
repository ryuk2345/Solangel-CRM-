<?php

namespace App\Filament\Resources\ServiceResource\Pages;

use App\Filament\Resources\ServiceResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Str; // 🌟 Necesitamos esto para generar códigos

class CreateService extends CreateRecord
{
    protected static string $resource = ServiceResource::class;

    protected function afterCreate(): void
    {
        $producto = $this->record;

        // Creamos una variante falsa en la BD para que el carrito la pueda leer.
        // 🌟 Le agregamos el campo 'sku' con un valor automático (ej. SRV-12345678)
        \App\Models\ProductVariant::create([
            'product_id' => $producto->id,
            'size' => null,
            'color_name' => null,
            'sku' => 'SRV-' . strtoupper(Str::random(8)), // 🌟 AQUI ESTA LA SOLUCION
        ]);
    }
}