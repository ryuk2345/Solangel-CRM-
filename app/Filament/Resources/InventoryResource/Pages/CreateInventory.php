<?php

namespace App\Filament\Resources\InventoryResource\Pages;

use App\Filament\Resources\InventoryResource;
use App\Models\Warehouse;
use Filament\Resources\Pages\CreateRecord;

class CreateInventory extends CreateRecord
{
    protected static string $resource = InventoryResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (empty($data['branch_id']) && !empty($data['warehouse_id'])) {
            $data['branch_id'] = Warehouse::where('id', $data['warehouse_id'])->value('branch_id')
                ?? auth()->user()?->branch_id ?? 1;
        }

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
