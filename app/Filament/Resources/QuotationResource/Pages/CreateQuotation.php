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
