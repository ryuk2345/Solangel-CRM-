<?php

namespace App\Filament\Resources\SupplierResource\Pages;

use App\Filament\Resources\PurchaseResource;
use App\Filament\Resources\SupplierResource;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ManageRecords;

class ManageSuppliers extends ManageRecords
{
    protected static string $resource = SupplierResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }

    public function getTabs(): array
    {
        return [
            'compras' => Tab::make('Compras'),
            'proveedores' => Tab::make('Proveedores'),
        ];
    }

    public function getDefaultActiveTab(): string | int | null
    {
        return 'proveedores';
    }

    public function updatedActiveTab(): void
    {
        if ($this->activeTab === 'compras') {
            $this->redirect(PurchaseResource::getUrl());
        }
    }
}
