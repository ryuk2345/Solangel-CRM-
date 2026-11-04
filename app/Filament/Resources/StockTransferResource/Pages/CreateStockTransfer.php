<?php

namespace App\Filament\Resources\StockTransferResource\Pages;

use App\Filament\Resources\StockTransferResource;
use App\Models\Branch;
use App\Models\Inventory;
use App\Models\ProductVariant;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateStockTransfer extends CreateRecord
{
    protected static string $resource = StockTransferResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = auth()->user();
        if (!$user->seesAllBranches()) {
            $data['origin_branch_id'] = $user->branch_id ?? 1;
        }

        $isInterBranch = (int)$data['origin_branch_id'] !== (int)$data['destination_branch_id'];
        $data['type'] = $isInterBranch ? 'entre_sucursales' : 'interno';
        $data['sent_by_user_id'] = $user->id;
        $data['sent_at'] = now();

        if (!$isInterBranch) {
            // Traspaso interno entre almacenes de la misma tienda: se completa de inmediato sin recepción ni guía
            $data['status'] = 'completado';
            $data['received_by_user_id'] = $user->id;
            $data['received_at'] = now();
        } else {
            $data['status'] = 'en_transito';
        }

        if ((int)$data['origin_warehouse_id'] === (int)$data['destination_warehouse_id']) {
            throw ValidationException::withMessages([
                'destination_warehouse_id' => 'El almacén de destino no puede ser idéntico al almacén de origen.',
            ]);
        }

        if ($isInterBranch) {
            $origBranch = Branch::find($data['origin_branch_id']);
            $destBranch = Branch::find($data['destination_branch_id']);
            $origCode = trim($origBranch?->sunat_code ?? '');
            $destCode = trim($destBranch?->sunat_code ?? '');

            $isOrigValid = !empty($origCode) && ((int)$origBranch->id === 1 || $origCode !== '0000');
            $isDestValid = !empty($destCode) && ((int)$destBranch->id === 1 || $destCode !== '0000');

            if (!$isOrigValid) {
                throw ValidationException::withMessages([
                    'destination_branch_id' => "La tienda de origen '{$origBranch?->name}' no tiene un código SUNAT válido (las sucursales secundarias requieren código distinto de 0000). Los traspasos entre tiendas exigen Guía de Remisión.",
                ]);
            }

            if (!$isDestValid) {
                throw ValidationException::withMessages([
                    'destination_branch_id' => "La tienda de destino '{$destBranch?->name}' no tiene un código SUNAT válido (las sucursales secundarias requieren código distinto de 0000). Los traspasos entre tiendas exigen Guía de Remisión.",
                ]);
            }
        }

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        return DB::transaction(function () use ($data) {
            $itemsData = $data['items'] ?? [];
            unset($data['items']);

            if (empty($itemsData)) {
                throw ValidationException::withMessages([
                    'items' => 'Debe agregar al menos un producto al traspaso.',
                ]);
            }

            $originWarehouseId = $data['origin_warehouse_id'];
            $destWarehouseId = $data['destination_warehouse_id'];
            $destBranchId = $data['destination_branch_id'];
            $isInterno = $data['type'] === 'interno';

            // 1. Verificar y descontar stock del almacén de origen con bloqueo pesimista
            foreach ($itemsData as $item) {
                $variantId = $item['product_variant_id'];
                $qtySent = (int)$item['quantity_sent'];

                $inv = Inventory::where('product_variant_id', $variantId)
                    ->where('warehouse_id', $originWarehouseId)
                    ->lockForUpdate()
                    ->first();

                $variant = ProductVariant::with(['product.branches'])->find($variantId);
                $nombreProd = $variant?->product?->name ?? "Variante #{$variantId}";

                // Regla 9: Traspasos entre tiendas: el producto debe estar habilitado en el catálogo de la tienda de destino
                if (!$isInterno) {
                    $isEnabledInDest = $variant && $variant->product && $variant->product->branches->contains('id', (int)$destBranchId);
                    if (!$isEnabledInDest) {
                        $destBranchName = \App\Models\Branch::find($destBranchId)?->name ?? "ID {$destBranchId}";
                        $sku = $variant?->sku ?? "ID {$variantId}";
                        throw ValidationException::withMessages([
                            'items' => "El producto '{$nombreProd}' (SKU: {$sku}) no está habilitado en el catálogo de la tienda de destino '{$destBranchName}'. Un administrador (super_admin) debe habilitarlo en dicha tienda antes de poder realizar el traspaso.",
                        ]);
                    }
                }

                if (!$inv || $inv->stock < $qtySent) {
                    $disponible = $inv ? $inv->stock : 0;
                    throw ValidationException::withMessages([
                        'items' => "Stock insuficiente para '{$nombreProd}' en el almacén de origen. Disponible: {$disponible}, Solicitado: {$qtySent}.",
                    ]);
                }

            }

            // 2. Crear cabecera del traspaso
            $transfer = static::getModel()::create($data);

            // 3. Crear items del traspaso
            foreach ($itemsData as $item) {
                $qtySent = (int)$item['quantity_sent'];
                $transfer->items()->create([
                    'product_variant_id' => $item['product_variant_id'],
                    'quantity_sent' => $qtySent,
                    'quantity_received' => $isInterno ? $qtySent : null,
                    'quantity_difference' => 0,
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            // 4. Mover el stock por el kardex (salida del origen; entrada inmediata si es interno)
            $guideDoc = $transfer->despatch?->document_number ?? $transfer->number;
            foreach ($itemsData as $item) {
                $qtySent = (int) $item['quantity_sent'];
                \App\Services\StockService::move((int) $item['product_variant_id'], (int) $originWarehouseId, -$qtySent, 'transfer_out', $transfer, "Traspaso {$transfer->number}", null, null, $guideDoc);
                if ($isInterno) {
                    \App\Services\StockService::move((int) $item['product_variant_id'], (int) $destWarehouseId, $qtySent, 'transfer_in', $transfer, "Traspaso {$transfer->number}", null, null, $guideDoc);
                }
            }

            return $transfer;
        });
    }

    protected function getCreatedNotification(): ?\Filament\Notifications\Notification
    {
        $record = $this->getRecord();
        if ($record->type === 'entre_sucursales') {
            return \Filament\Notifications\Notification::make()
                ->title('Traspaso en Tránsito Registrado')
                ->body("Traspaso {$record->number} registrado. ⚠️ AVISO: Al ser un traslado entre tiendas, la Guía de Remisión debe ser emitida y aceptada por SUNAT antes de que la mercadería salga del local de origen.")
                ->warning()
                ->persistent();
        }

        return \Filament\Notifications\Notification::make()
            ->title('Traspaso Interno Completado')
            ->body("Traspaso {$record->number} completado exitosamente de inmediato entre almacenes.")
            ->success();
    }
}
