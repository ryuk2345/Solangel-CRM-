<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->before(function (Actions\DeleteAction $action, \App\Models\User $record) {
                    if ($record->sales()->count() > 0) {
                        \Filament\Notifications\Notification::make()
                            ->title('No se puede eliminar el usuario')
                            ->body('Este usuario tiene ventas registradas en el sistema. No puede ser eliminado.')
                            ->danger()
                            ->send();

                        $action->cancel();
                        return;
                    }

                    if ($record->cashRegisters()->count() > 0) {
                        \Filament\Notifications\Notification::make()
                            ->title('No se puede eliminar el usuario')
                            ->body('Este usuario tiene aperturas/cierres de caja registrados en el sistema.')
                            ->danger()
                            ->send();

                        $action->cancel();
                        return;
                    }

                    if ($record->hasRole('super_admin') && \App\Models\User::role('super_admin')->count() <= 1) {
                        \Filament\Notifications\Notification::make()
                            ->title('No se puede eliminar el usuario')
                            ->body('No puedes eliminar al único super administrador del sistema.')
                            ->danger()
                            ->send();

                        $action->cancel();
                        return;
                    }
                }),
        ];
    }
}
