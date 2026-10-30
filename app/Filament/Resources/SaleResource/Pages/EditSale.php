<?php

namespace App\Filament\Resources\SaleResource\Pages;

use App\Filament\Resources\SaleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSale extends EditRecord
{
    protected static string $resource = SaleResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // ─── VALIDAR LÍNEAS EN PRECIO 0 PARA BOLETAS Y FACTURAS ───
        $serie = trim((string)($data['serie'] ?? $this->record?->serie ?? ''));
        $esElectronico = !str_starts_with($serie, 'NV');
        if ($esElectronico) {
            if (!empty($data['is_quick_sale'])) {
                if ((float)($data['quick_sale_total'] ?? 0) <= 0) {
                    \Filament\Notifications\Notification::make()
                        ->title('Línea con precio 0 no permitida')
                        ->body('Las boletas y facturas no admiten líneas con precio 0. Escribe la placa u observación en la descripción del producto.')
                        ->danger()
                        ->persistent()
                        ->send();
                    $this->halt();
                }
            } else {
                $itemsCheck = $this->data['items'] ?? $data['items'] ?? [];
                foreach ($itemsCheck as $item) {
                    $precio = (float)($item['price'] ?? 0);
                    $cant = (float)($item['quantity'] ?? 1);
                    $totalLinea = (isset($item['total']) && is_numeric($item['total']))
                        ? (float)$item['total']
                        : ($precio * $cant);

                    if ($precio <= 0 || $totalLinea <= 0) {
                        \Filament\Notifications\Notification::make()
                            ->title('Línea con precio 0 no permitida')
                            ->body('Las boletas y facturas no admiten líneas con precio 0. Escribe la placa u observación en la descripción del producto.')
                            ->danger()
                            ->persistent()
                            ->send();
                        $this->halt();
                    }
                }
            }
        }

        // ─── VALIDAR DESGLOSE DE PAGOS ───
        $totalVenta = 0;
        if (!empty($data['is_quick_sale'])) {
            $totalVenta = (float)($data['quick_sale_total'] ?? 0);
        } else {
            $totalVenta = collect($data['items'] ?? [])
                ->map(fn($item) => (float)($item['total'] ?? 0))
                ->sum();
        }

        $totalPagos = collect($data['payments'] ?? [])
            ->map(fn($p) => (float)($p['amount'] ?? 0))
            ->sum();

        if (abs($totalPagos - $totalVenta) > 0.01) {
            \Filament\Notifications\Notification::make()
                ->title('⚠️ Error en Desglose de Pagos')
                ->body("La suma de los pagos (S/ " . number_format($totalPagos, 2) . ") debe ser exactamente igual al total de la venta (S/ " . number_format($totalVenta, 2) . ").")
                ->danger()
                ->send();
            $this->halt();
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
