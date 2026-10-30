<?php

namespace App\Filament\Resources\WarehouseResource\Pages;

use App\Filament\Resources\WarehouseResource;
use App\Models\Warehouse;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditWarehouse extends EditRecord
{
    protected static string $resource = WarehouseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->before(function (Actions\DeleteAction $action, Warehouse $record) {
                    if ($record->is_default) {
                        Notification::make()
                            ->title('No se puede eliminar el almacén')
                            ->body('Este es el almacén por defecto de la tienda. Para eliminarlo, primero debes marcar otro almacén como por defecto.')
                            ->danger()
                            ->send();

                        $action->cancel();
                        return;
                    }

                    $otherActive = Warehouse::where('branch_id', $record->branch_id)
                        ->where('id', '!=', $record->id)
                        ->where('is_active', true)
                        ->count();

                    if ($otherActive === 0) {
                        Notification::make()
                            ->title('No se puede eliminar el almacén')
                            ->body('No puedes eliminar el único almacén activo de esta tienda.')
                            ->danger()
                            ->send();

                        $action->cancel();
                        return;
                    }

                    $stockCount = $record->inventories()->where('stock', '>', 0)->count();
                    $salesCount = $record->sales()->count();

                    if ($stockCount > 0 || $salesCount > 0) {
                        Notification::make()
                            ->title('No se puede eliminar el almacén')
                            ->body("Este almacén tiene {$stockCount} productos con stock y {$salesCount} ventas registradas. Desactívalo en su lugar.")
                            ->danger()
                            ->send();

                        $action->cancel();
                        return;
                    }
                }),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
