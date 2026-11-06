<?php

namespace App\Filament\Resources\PurchaseResource\Pages;

use App\Filament\Resources\PurchaseResource;
use App\Filament\Resources\SupplierResource;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;

class ListPurchases extends ListRecords
{
    protected static string $resource = PurchaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Registrar compra'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'compras' => Tab::make('Compras'),
            'proveedores' => Tab::make('Proveedores'),
        ];
    }

    public function updatedActiveTab(): void
    {
        if ($this->activeTab === 'proveedores') {
            $this->redirect(SupplierResource::getUrl());
        }
    }
}
