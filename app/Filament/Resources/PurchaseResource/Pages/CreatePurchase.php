<?php

namespace App\Filament\Resources\PurchaseResource\Pages;

use App\Filament\Resources\PurchaseResource;
use App\Services\PurchaseService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreatePurchase extends CreateRecord
{
    protected static string $resource = PurchaseResource::class;

    protected static ?string $title = 'Registrar compra';

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return PurchaseService::register($data, array_values($this->data['lines'] ?? []));
        } catch (ValidationException $e) {
            Notification::make()->title('No se pudo registrar la compra')
                ->body(collect($e->errors())->flatten()->implode("\n"))->danger()->persistent()->send();
            $this->halt();
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Compra registrada: el stock ya ingresó al almacén';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
