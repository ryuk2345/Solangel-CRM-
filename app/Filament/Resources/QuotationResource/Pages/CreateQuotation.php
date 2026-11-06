<?php

namespace App\Filament\Resources\QuotationResource\Pages;

use App\Filament\Resources\QuotationResource;
use Filament\Resources\Pages\CreateRecord;

class CreateQuotation extends CreateRecord
{
    protected static string $resource = QuotationResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = $data['user_id'] ?? auth()->id();

        // Precios calculados en el servidor con la misma regla de la venta
        $items = collect($this->data['items'] ?? [])->filter(fn ($i) => !empty($i['product_variant_id']))->all();
        $pricing = \App\Services\PricingService::apply(
            $items,
            isset($data['customer_id']) ? (int) $data['customer_id'] : null,
            isset($data['branch_id']) ? (int) $data['branch_id'] : null,
            null,
            auth()->user(),
        );
        foreach ($pricing['lines'] as $key => $line) {
            $pricing['lines'][$key]['subtotal'] = round(((float) $line['total']) / 1.18, 2);
        }
        $this->data['items'] = $pricing['lines'];

        $data['total'] = 0;
        $data['subtotal'] = 0;
        $data['igv'] = 0;
        return $data;
    }

    protected function afterCreate(): void
    {
        $quotation = $this->record;
        $totalReal = 0;

        foreach ($quotation->items as $item) {
            $totalReal += $item->total;
        }

        $subtotal = round($totalReal / 1.18, 2);
        $igv = round($totalReal - $subtotal, 2);

        $quotation->update([
            'total' => $totalReal,
            'subtotal' => $subtotal,
            'igv' => $igv,
        ]);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
