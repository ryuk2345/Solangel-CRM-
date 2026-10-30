<?php

namespace App\Filament\Resources\DespatchResource\Pages;

use App\Filament\Resources\DespatchResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDespatch extends CreateRecord
{
    protected static string $resource = DespatchResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    public function mount(): void
    {
        parent::mount();

        $saleId = request()->query('sale_id');
        if ($saleId) {
            $sale = \App\Models\Sale::with(['items', 'customer', 'branch'])->find($saleId);
            if ($sale) {
                $items = [];
                foreach ($sale->items as $item) {
                    $items[] = [
                        'product_variant_id' => $item->product_variant_id,
                        'product_name' => $item->product_name,
                        'quantity' => $item->quantity,
                        'unit_code' => 'NIU',
                    ];
                }

                $this->form->fill([
                    'sale_id' => $sale->id,
                    'customer_id' => $sale->customer_id,
                    'branch_id' => $sale->branch_id,
                    'departure_address' => $sale->branch?->address,
                    'departure_ubigeo' => $sale->branch?->ubigeo,
                    'arrival_address' => $sale->customer?->address,
                    'arrival_ubigeo' => $sale->customer?->ubigeo ?? '150101',
                    'items' => $items,
                    'serie' => 'T001',
                    'issue_date' => now()->format('Y-m-d'),
                    'transfer_date' => now()->format('Y-m-d'),
                    'transfer_reason_code' => '01',
                    'mode_of_transport' => '02',
                    'total_weight' => 1.00,
                    'number_of_packages' => 1,
                ]);
            }
        }
    }
}
