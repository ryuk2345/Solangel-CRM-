<?php

namespace App\Filament\Resources\AuditSaleResource\Pages;

use App\Filament\Resources\AuditSaleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAuditSale extends EditRecord
{
    protected static string $resource = AuditSaleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ViewAction::make(),
            Actions\DeleteAction::make(),
        ];
    }
}
