<?php

namespace App\Filament\Resources\SaleResource\Pages;

use App\Filament\Resources\SaleResource;
use App\Services\SaleAnulacionService;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Notifications\Notification;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;

class ViewSale extends ViewRecord
{
    protected static string $resource = SaleResource::class;

    public function mount(int | string $record): void
    {
        parent::mount($record);

        $user = auth()->user();
        if ($user && !$user->hasRole('super_admin') && (int)$user->branch_id !== (int)$this->record->branch_id) {
            abort(403, 'No tiene permiso para ver comprobantes de otra sucursal.');
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('anular')
                ->label('Anular Venta')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(fn ($record) => str_starts_with($record->serie, 'NV')
                    ? 'Anular Venta Interna'
                    : 'Anular Comprobante Electrónico (Nota de Crédito)'
                )
                ->modalDescription(fn ($record) => str_starts_with($record->serie, 'NV')
                    ? 'La venta quedará marcada como anulada y el stock regresará al inventario.'
                    : 'Esto enviará una Nota de Crédito oficial a SUNAT y devolverá el stock al inventario. Esta acción es irreversible.'
                )
                ->modalSubmitActionLabel('Proceder con la Anulación')
                ->form(function ($record): array {
                    if (str_starts_with($record->serie, 'NV')) {
                        return [
                            Textarea::make('motivo_texto')
                                ->label('Motivo de la Anulación Interna')
                                ->required()
                                ->placeholder('Ej. Devolución por talla incorrecta.'),
                        ];
                    }

                    return [
                        Select::make('codigo_motivo')
                            ->label('Motivo Oficial SUNAT')
                            ->options([
                                '01' => 'Anulación de la operación',
                                '02' => 'Anulación por error en el RUC/DNI',
                                '06' => 'Devolución total',
                            ])
                            ->default('01')
                            ->required(),
                        Textarea::make('motivo_texto')
                            ->label('Detalle de la anulación (Opcional)')
                            ->placeholder('Ej. Devolución por insatisfacción o error en comprobante')
                            ->maxLength(250),
                    ];
                })
                ->visible(fn ($record) => $record->sunat_status !== 'anulado' && $record->sunat_status !== 'rechazado')
                ->action(function (array $data, $record) {
                    $res = SaleAnulacionService::anularVenta($record, $data, auth()->user());

                    if (!isset($res['success']) || $res['success'] !== true) {
                        Notification::make()
                            ->title('Error al anular venta')
                            ->body($res['error'] ?? 'No se pudo procesar la anulación.')
                            ->danger()
                            ->persistent()
                            ->send();
                        return;
                    }

                    if (($res['type'] ?? '') === 'credit_note') {
                        Notification::make()
                            ->title($res['title'] ?? 'Nota de Crédito Emitida')
                            ->body($res['message'])
                            ->success()
                            ->actions([
                                NotificationAction::make('imprimir_ticket')
                                    ->label('Imprimir Ticket')
                                    ->icon('heroicon-o-printer')
                                    ->color('success')
                                    ->url(route('sales.notes.ticket', ['sale' => $record->id, 'tipo' => 'credito']), shouldOpenInNewTab: true),
                                NotificationAction::make('imprimir_a4')
                                    ->label('Imprimir A4')
                                    ->icon('heroicon-o-document-text')
                                    ->color('info')
                                    ->url(route('sales.notes.a4', ['sale' => $record->id, 'tipo' => 'credito']), shouldOpenInNewTab: true),
                            ])
                            ->persistent()
                            ->send();
                    } else {
                        Notification::make()
                            ->title($res['title'] ?? 'Venta Anulada')
                            ->body($res['message'])
                            ->success()
                            ->send();
                    }
                }),

            Actions\Action::make('reemitir_comprobante')
                ->label('Reemitir Comprobante')
                ->icon('heroicon-o-arrow-path')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(fn (Sale $record) => "Reemitir Comprobante {$record->serie}-" . str_pad($record->correlative, 8, '0', STR_PAD_LEFT))
                ->modalDescription(fn (Sale $record) => $record->sunat_status === 'pendiente'
                    ? 'Este comprobante está pendiente. Se reintentará primero con su número original. Si SUNAT lo rechaza, se reemitirá con un nuevo correlativo y fecha de hoy.'
                    : 'Este comprobante fue rechazado por SUNAT. Se asignará un nuevo correlativo de la serie con fecha de hoy y se enviará a SUNAT. El comprobante anterior quedará archivado en el historial. Stock y caja no se modificarán.'
                )
                ->modalSubmitActionLabel('Confirmar y Procesar')
                ->visible(fn ($record) =>
                    auth()->user()?->hasRole('super_admin') &&
                    !str_starts_with($record->serie, 'NV') &&
                    in_array($record->sunat_status, ['rechazado', 'pendiente']) &&
                    (!$record->is_quick_sale || $record->is_audited)
                )
                ->action(function ($record) {
                    $service = new \App\Services\SaleReemissionService();
                    $res = $service->reemitirOReintentar($record, auth()->user());

                    if (!empty($res['success'])) {
                        Notification::make()
                            ->title('Comprobante Procesado con Éxito')
                            ->body($res['message'])
                            ->success()
                            ->persistent()
                            ->send();
                    } else {
                        Notification::make()
                            ->title('Atención al procesar comprobante')
                            ->body($res['error'] ?? 'Ocurrió un error al procesar la reemisión.')
                            ->danger()
                            ->persistent()
                            ->send();
                    }
                }),

            Actions\Action::make('regularizar_nc')
                ->label('Regularizar Nota de Crédito')
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Regularizar Nota de Crédito en SUNAT')
                ->modalDescription('Esta venta figura anulada en el sistema pero NO tiene Nota de Crédito en SUNAT. Se emitirá la Nota de Crédito oficial ante SUNAT para anular tributariamente el comprobante. El inventario NO se devolverá de nuevo porque ya fue devuelto en la anulación previa.')
                ->form([
                    Select::make('codigo_motivo')
                        ->label('Motivo Oficial SUNAT')
                        ->options([
                            '01' => 'Anulación de la operación',
                            '02' => 'Anulación por error en el RUC/DNI',
                            '06' => 'Devolución total',
                        ])
                        ->default('01')
                        ->required(),
                    Textarea::make('motivo_texto')
                        ->label('Detalle / Observación')
                        ->placeholder('Ej. Regularización de anulación previa')
                        ->maxLength(250),
                ])
                ->visible(fn ($record) =>
                    !str_starts_with($record->serie, 'NV') &&
                    $record->sunat_status === 'anulado' &&
                    \App\Services\SunatNoteReader::buscarNotaParaVenta($record, 'credito') === null
                )
                ->action(function (array $data, $record) {
                    $res = SaleAnulacionService::regularizarNotaCredito($record, $data, auth()->user());

                    if (!isset($res['success']) || $res['success'] !== true) {
                        Notification::make()
                            ->title('Error al regularizar')
                            ->body($res['error'] ?? 'No se pudo emitir la Nota de Crédito.')
                            ->danger()
                            ->persistent()
                            ->send();
                        return;
                    }

                    Notification::make()
                        ->title($res['title'])
                        ->body($res['message'])
                        ->success()
                        ->actions([
                            NotificationAction::make('imprimir_ticket')
                                ->label('Imprimir Ticket')
                                ->icon('heroicon-o-printer')
                                ->color('success')
                                ->url(route('sales.notes.ticket', ['sale' => $record->id, 'tipo' => 'credito']), shouldOpenInNewTab: true),
                            NotificationAction::make('imprimir_a4')
                                ->label('Imprimir A4')
                                ->icon('heroicon-o-document-text')
                                ->color('info')
                                ->url(route('sales.notes.a4', ['sale' => $record->id, 'tipo' => 'credito']), shouldOpenInNewTab: true),
                        ])
                        ->persistent()
                        ->send();
                }),
        ];
    }
}