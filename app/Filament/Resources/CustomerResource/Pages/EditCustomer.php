<?php

namespace App\Filament\Resources\CustomerResource\Pages;

use App\Filament\Resources\CustomerResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Filament\Notifications\Notification; // Asegúrate de importar esto

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Interceptamos el botón de borrar
            Actions\DeleteAction::make()
                ->before(function ($record, Actions\DeleteAction $action) {
                    // Verificamos si este cliente tiene ventas registradas
                    $tieneVentas = \App\Models\Sale::where('customer_id', $record->id)->exists();
                    
                    if ($tieneVentas) {
                        // Lanzamos la alerta roja amigable
                        Notification::make()
                            ->danger()
                            ->title('No se puede eliminar')
                            ->body('Este cliente ya tiene comprobantes de pago registrados en el sistema. Por seguridad contable, no puede ser borrado.')
                            ->persistent()
                            ->send();
                        
                        // Detenemos el proceso para que no salga la pantalla de error
                        $action->halt(); 
                    }
                }),
        ];
    }
}