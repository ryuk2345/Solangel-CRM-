<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Filament\Resources\ProductResource;
use App\Services\VariantGenerator;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    protected function afterCreate(): void
    {
        $product = $this->record;
        $user = Auth::user();

        // Fuera del administrador, el modelo queda en la tienda del usuario
        if ($user && !$user->seesAllBranches()) {
            $product->branches()->sync([$user->branch_id ?? 1]);
        } elseif ($product->branches()->doesntExist()) {
            $product->branches()->sync([$user?->branch_id ?? 1]);
        }

        // Generar las variantes color × talla (crea también sus filas de inventario en 0)
        $created = VariantGenerator::generate(
            $product,
            $this->data['gen_colors'] ?? [],
            $this->data['gen_sizes'] ?? [],
        );

        Notification::make()
            ->title("Modelo creado con {$created} variantes")
            ->body('Revisa precios por talla y códigos de barras en la lista de variantes.')
            ->success()
            ->send();
    }

    protected function getCreatedNotification(): ?Notification
    {
        return null;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
