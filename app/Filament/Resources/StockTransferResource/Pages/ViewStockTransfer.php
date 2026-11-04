<?php

namespace App\Filament\Resources\StockTransferResource\Pages;

use App\Filament\Resources\StockTransferResource;
use App\Models\Inventory;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\DB;

class ViewStockTransfer extends ViewRecord
{
    protected static string $resource = StockTransferResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('recibir_traspaso')
                ->label('Recibir Traspaso')
                ->icon('heroicon-o-inbox-arrow-down')
                ->color('success')
                ->visible(fn ($record) => $record->status === 'en_transito' && (auth()->user()?->seesAllBranches() || (int)auth()->user()?->branch_id === (int)$record->destination_branch_id))
                ->form(function ($record) {
                    $fields = [];
                    foreach ($record->items as $item) {
                        $variant = $item->productVariant;
                        $prodName = $variant?->product?->name ?? 'Producto';
                        $sku = $variant?->sku ?? '-';

                        $fields[] = Forms\Components\Fieldset::make("Item #{$item->id}: [{$sku}] {$prodName}")
                            ->schema([
                                Forms\Components\TextInput::make("items.{$item->id}.quantity_sent")
                                    ->label('Cantidad Enviada')
                                    ->default($item->quantity_sent)
                                    ->disabled()
                                    ->dehydrated(false),
                                Forms\Components\TextInput::make("items.{$item->id}.quantity_received")
                                    ->label('Cantidad Recibida')
                                    ->numeric()
                                    ->integer()
                                    ->minValue(0)
                                    ->maxValue($item->quantity_sent)
                                    ->default($item->quantity_sent)
                                    ->required(),
                                Forms\Components\TextInput::make("items.{$item->id}.notes")
                                    ->label('Observación del item')
                                    ->default($item->notes),
                            ])->columns(3);
                    }

                    $fields[] = Forms\Components\Textarea::make('reception_notes')
                        ->label('Observaciones generales de recepción')
                        ->maxLength(500);

                    return $fields;
                })
                ->action(function (array $data, $record) {
                    DB::transaction(function () use ($data, $record) {
                        $lockedTransfer = $record->fresh();
                        $lockedTransfer = \App\Models\StockTransfer::where('id', $lockedTransfer->id)->lockForUpdate()->first();

                        if ($lockedTransfer->status !== 'en_transito') {
                            Notification::make()
                                ->title('Error')
                                ->body('El traspaso ya no se encuentra en tránsito.')
                                ->danger()
                                ->send();
                            return;
                        }

                        $itemsInput = $data['items'] ?? [];
                        $hasDiff = false;

                        foreach ($lockedTransfer->items as $item) {
                            $itemData = $itemsInput[$item->id] ?? null;
                            $receivedQty = $itemData ? (int)$itemData['quantity_received'] : $item->quantity_sent;
                            $diff = $item->quantity_sent - $receivedQty;

                            if ($diff !== 0) {
                                $hasDiff = true;
                            }

                            $item->update([
                                'quantity_received' => $receivedQty,
                                'quantity_difference' => $diff,
                                'notes' => $itemData['notes'] ?? $item->notes,
                            ]);

                            if ($receivedQty > 0) {
                                $guideDoc = $lockedTransfer->despatch?->document_number ?? $lockedTransfer->number;
                                \App\Services\StockService::move((int) $item->product_variant_id, (int) $lockedTransfer->destination_warehouse_id, (int) $receivedQty, 'transfer_in', $lockedTransfer, "Recepción traspaso {$lockedTransfer->number}", null, null, $guideDoc);
                            }
                        }

                        $lockedTransfer->update([
                            'status' => $hasDiff ? 'recibido_con_diferencia' : 'completado',
                            'received_by_user_id' => auth()->id(),
                            'received_at' => now(),
                            'notes' => !empty($data['reception_notes']) 
                                ? ($lockedTransfer->notes ? $lockedTransfer->notes . ' | ' . $data['reception_notes'] : $data['reception_notes'])
                                : $lockedTransfer->notes,
                        ]);
                    });

                    Notification::make()
                        ->title('Traspaso recibido con éxito')
                        ->body('El stock ha sido ingresado al almacén de destino.')
                        ->success()
                        ->send();

                    $this->redirect(StockTransferResource::getUrl('view', ['record' => $record->id]));
                }),

            Actions\Action::make('anular_traspaso')
                ->label('Anular Traspaso')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Anular Traspaso de Stock')
                ->modalDescription(function ($record) {
                    $desc = 'Esta acción devolverá todo el stock de los productos al almacén de origen. Esta acción es irreversible.';
                    if ($record->despatch && $record->despatch->sunat_status === 'aceptado') {
                        $desc .= "\n\n⚠️ ATENCIÓN: Este traspaso cuenta con la Guía de Remisión {$record->despatch->serie}-{$record->despatch->correlative} aceptada por SUNAT. La anulación solo revierte el stock interno; la anulación o baja de la Guía de Remisión debe resolverse ante SUNAT por separado.";
                    }
                    return $desc;
                })
                ->visible(fn ($record) => auth()->user()?->seesAllBranches() && $record->status === 'en_transito')
                ->form([
                    Forms\Components\Textarea::make('cancellation_reason')
                        ->label('Motivo de la Anulación')
                        ->required()
                        ->maxLength(500),
                ])
                ->action(function (array $data, $record) {
                    DB::transaction(function () use ($data, $record) {
                        $lockedTransfer = \App\Models\StockTransfer::where('id', $record->id)->lockForUpdate()->first();

                        if ($lockedTransfer->status !== 'en_transito') {
                            Notification::make()
                                ->title('Error')
                                ->body('El traspaso ya no se encuentra en tránsito.')
                                ->danger()
                                ->send();
                            return;
                        }

                        // Devolver stock al almacén de origen
                        $guideDoc = $lockedTransfer->despatch?->document_number ?? $lockedTransfer->number;
                        foreach ($lockedTransfer->items as $item) {
                            \App\Services\StockService::move((int) $item->product_variant_id, (int) $lockedTransfer->origin_warehouse_id, (int) $item->quantity_sent, 'transfer_return', $lockedTransfer, "Traspaso anulado {$lockedTransfer->number}", null, null, $guideDoc);
                        }

                        $lockedTransfer->update([
                            'status' => 'anulado',
                            'cancelled_at' => now(),
                            'cancellation_reason' => $data['cancellation_reason'],
                        ]);
                    });

                    Notification::make()
                        ->title('Traspaso Anulado')
                        ->body('El traspaso fue anulado y el stock fue devuelto al almacén de origen.')
                        ->danger()
                        ->send();

                    $this->redirect(StockTransferResource::getUrl('view', ['record' => $record->id]));
                }),
        ];
    }
}
