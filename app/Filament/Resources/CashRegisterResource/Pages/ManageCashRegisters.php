<?php

namespace App\Filament\Resources\CashRegisterResource\Pages;

use App\Filament\Resources\CashRegisterResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageCashRegisters extends ManageRecords
{
    protected static string $resource = CashRegisterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Borramos el CreateAction que venía aquí por defecto. ¡Adiós botón rojo!
        ];
    }
}