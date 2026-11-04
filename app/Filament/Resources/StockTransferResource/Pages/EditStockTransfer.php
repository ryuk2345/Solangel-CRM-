<?php

namespace App\Filament\Resources\StockTransferResource\Pages;

use App\Filament\Resources\StockTransferResource;
use App\Models\Inventory;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EditStockTransfer extends EditRecord
{
    protected static string $resource = StockTransferResource::class;

    public function mount(int | string $record): void
    {
        parent::mount($record);

        $transfer = $this->getRecord();
        $user = auth()->user();

        if ($transfer->status !== 'en_transito') {
            Notification::make()
                ->title('Traspaso cerrado')
                ->body('Este traspaso ya no se encuentra en tránsito y no puede ser modificado.')
                ->warning()
                ->send();

            $this->redirect(StockTransferResource::getUrl('view', ['record' => $transfer->id]));
            return;
        }

        if (!$user->seesAllBranches() && (int)$user->branch_id !== (int)$transfer->destination_branch_id) {
            Notification::make()
                ->title('Acceso no autorizado')
                ->body('Solo el personal de la tienda de destino puede recibir este traspaso.')
                ->danger()
                ->send();

            $this->redirect(StockTransferResource::getUrl('view', ['record' => $transfer->id]));
            return;
        }
    }

    protected function getHeaderActions(): array
    {
        return []; // No permitir eliminar
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return DB::transaction(function () use ($record, $data) {
            $lockedTransfer = static::getModel()::where('id', $record->id)->lockForUpdate()->first();

            if ($lockedTransfer->status !== 'en_transito') {
                throw ValidationException::withMessages([
                    'items' => 'El traspaso ya fue procesado o anulado previamente.',
                ]);
            }

            $itemsData = $data['items'] ?? [];
            $hasDifference = false;

            foreach ($itemsData as $itemData) {
                $transferItem = $lockedTransfer->items()->where('id', $itemData['id'] ?? 0)->lockForUpdate()->first();
                if (!$transferItem) {
                    continue;
                }

                $qtyReceived = (int)($itemData['quantity_received'] ?? $transferItem->quantity_sent);
                $diff = $transferItem->quantity_sent - $qtyReceived;

                if ($diff !== 0) {
                    $hasDifference = true;
                }

                $transferItem->update([
                    'quantity_received' => $qtyReceived,
                    'quantity_difference' => $diff,
                    'notes' => $itemData['notes'] ?? $transferItem->notes,
                ]);

                // Incrementar stock en el almacén de destino
                if ($qtyReceived > 0) {
                    $guideDoc = $lockedTransfer->despatch?->document_number ?? $lockedTransfer->number;
                    \App\Services\StockService::move((int) $transferItem->product_variant_id, (int) $lockedTransfer->destination_warehouse_id, (int) $qtyReceived, 'transfer_in', $lockedTransfer, "Recepción traspaso {$lockedTransfer->number}", null, null, $guideDoc);
                }
            }

            $newStatus = $hasDifference ? 'recibido_con_diferencia' : 'completado';

            $lockedTransfer->update([
                'status' => $newStatus,
                'received_by_user_id' => auth()->id(),
                'received_at' => now(),
                'notes' => !empty($data['notes']) ? $data['notes'] : $lockedTransfer->notes,
            ]);

            return $lockedTransfer;
        });
    }
}
