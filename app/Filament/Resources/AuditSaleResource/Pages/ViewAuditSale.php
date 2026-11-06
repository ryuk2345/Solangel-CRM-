<?php

namespace App\Filament\Resources\AuditSaleResource\Pages;

use App\Filament\Resources\AuditSaleResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;
use Filament\Notifications\Notification;
use App\Models\Inventory;
use App\Services\SunatService;
use Exception;

class ViewAuditSale extends ViewRecord
{
    protected static string $resource = AuditSaleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // 🔴 BOTÓN 1: NOTA DE CRÉDITO (Anula en SUNAT)
            Actions\Action::make('anular_sunat')
                ->label('Emitir Nota de Crédito')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Emitir Nota de Crédito Oficial')
                ->modalDescription('Esto enviará una Nota de Crédito a SUNAT y devolverá las prendas al stock. Esta acción es irreversible.')
                ->form([
                    \Filament\Forms\Components\Select::make('codigo_motivo')
                        ->label('Motivo Oficial SUNAT')
                        ->options([
                            '01' => 'Anulación de la operación',
                            '02' => 'Anulación por error en el RUC/DNI',
                            '06' => 'Devolución total',
                        ])
                        ->default('01')
                        ->required(),
                    \Filament\Forms\Components\Textarea::make('motivo_texto') // 🌟 VARIABLE UNIFICADA Y SEGURA
                        ->label('Detalle interno (Opcional)')
                        ->maxLength(250),
                ])
                // 🛡️ LÓGICA: Solo si NO es NV y el estado es estrictamente "aceptado"
                ->visible(fn ($record) => !str_starts_with($record->serie, 'NV') && $record->sunat_status === 'aceptado')
                ->action(function (array $data, $record) {
                    $res = \App\Services\SaleAnulacionService::anularVenta($record, $data, auth()->user());

                    if (!isset($res['success']) || $res['success'] !== true) {
                        Notification::make()
                            ->title('Error al anular en SUNAT')
                            ->body($res['error'] ?? 'No se pudo emitir la Nota de Crédito.')
                            ->danger()
                            ->persistent()
                            ->send();
                        return;
                    }

                    Notification::make()
                        ->title($res['title'] ?? 'Nota de Crédito Emitida')
                        ->body($res['message'])
                        ->success()
                        ->actions([
                            \Filament\Notifications\Actions\Action::make('imprimir_ticket')
                                ->label('Imprimir Ticket')
                                ->icon('heroicon-o-printer')
                                ->color('success')
                                ->url(route('sales.notes.ticket', ['sale' => $record->id, 'tipo' => 'credito']), shouldOpenInNewTab: true),
                            \Filament\Notifications\Actions\Action::make('imprimir_a4')
                                ->label('Imprimir A4')
                                ->icon('heroicon-o-document-text')
                                ->color('info')
                                ->url(route('sales.notes.a4', ['sale' => $record->id, 'tipo' => 'credito']), shouldOpenInNewTab: true),
                        ])
                        ->persistent()
                        ->send();
                }),

            // 🟠 BOTÓN 2: NOTA DE DÉBITO (Suma valor a SUNAT)
            Actions\Action::make('nota_debito')
                ->label('Emitir Nota de Débito')
                ->icon('heroicon-o-document-plus')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Emitir Nota de Débito Oficial')
                ->modalDescription('Esto enviará una Nota de Débito a SUNAT para cobrar un valor adicional asociado a esta boleta/factura.')
                ->form([
                    \Filament\Forms\Components\Select::make('codigo_motivo')
                        ->label('Motivo Oficial SUNAT')
                        ->options([
                            '01' => 'Intereses por mora',
                            '02' => 'Aumento en el valor',
                            '03' => 'Penalidades/otros conceptos',
                        ])
                        ->default('02')
                        ->required(),
                    \Filament\Forms\Components\TextInput::make('monto')
                        ->label('Monto adicional a cobrar (S/ con IGV incluido)')
                        ->numeric()
                        ->prefix('S/')
                        ->minValue(0.01)
                        ->required(),
                    \Filament\Forms\Components\Textarea::make('motivo_texto')
                        ->label('Concepto / Sustento')
                        ->maxLength(250)
                        ->required()
                        ->placeholder('Ej. Cobro por mora o ajuste en el precio'),
                ])
                // 🛡️ LÓGICA: Solo si NO es NV, está aceptada y NO tiene una ND ya aceptada por SUNAT
                ->visible(function ($record) {
                    if (str_starts_with($record->serie, 'NV') || $record->sunat_status !== 'aceptado') {
                        return false;
                    }
                    if (!empty($record->debit_note_serie) && !empty($record->debit_note_correlative)) {
                        $ruc = \App\Models\CompanySetting::first()?->ruc ?? env('EMPRESA_RUC', '20607603007');
                        $corrPad = str_pad($record->debit_note_correlative, 8, '0', STR_PAD_LEFT);
                        $cdrPath = storage_path("app/sunat/cdrs/R-{$ruc}-08-{$record->debit_note_serie}-{$corrPad}.zip");
                        if (\App\Services\SunatNoteReader::isCdrAccepted($cdrPath)) {
                            return false;
                        }
                    }
                    return true;
                })
                ->action(function (array $data, $record) {
                    try {
                        $service = new SunatService();
                        $monto = (float)($data['monto'] ?? 0);
                        $res = $service->emitirNotaDebito($record, $data['codigo_motivo'], $data['motivo_texto'], $monto, $data['motivo_texto']);
                        
                        if (!isset($res['success']) || $res['success'] !== true) {
                            Notification::make()
                                ->title('Error en SUNAT')
                                ->body($res['error'] ?? 'No se pudo emitir la Nota de Débito.')
                                ->danger()->persistent()->send();
                            return; 
                        }

                        $numeroND = "{$res['serie']}-" . str_pad($res['correlative'], 8, '0', STR_PAD_LEFT);

                        Notification::make()
                            ->title('Nota de Débito Emitida con Éxito')
                            ->body("Nota de Débito {$numeroND} aceptada por SUNAT.")
                            ->actions([
                                \Filament\Notifications\Actions\Action::make('imprimir_ticket')
                                    ->label('Imprimir Ticket')
                                    ->icon('heroicon-o-printer')
                                    ->color('success')
                                    ->url(route('sales.notes.ticket', ['sale' => $record->id, 'tipo' => 'debito']), shouldOpenInNewTab: true),
                                \Filament\Notifications\Actions\Action::make('imprimir_a4')
                                    ->label('Imprimir A4')
                                    ->icon('heroicon-o-document-text')
                                    ->color('info')
                                    ->url(route('sales.notes.a4', ['sale' => $record->id, 'tipo' => 'debito']), shouldOpenInNewTab: true),
                            ])
                            ->persistent()
                            ->success()
                            ->send();

                    } catch (Exception $e) {
                        Notification::make()
                            ->title('Error del Sistema')
                            ->body($e->getMessage())
                            ->danger()->persistent()->send();
                    }
                }),

            // 🟡 BOTÓN 3: SOLO PARA NOTAS DE VENTA INTERNAS
            Actions\Action::make('anular_interna')
                ->label('Anular Venta Interna')
                ->icon('heroicon-o-archive-box-x-mark')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Anular Registro Interno')
                ->modalDescription('La venta quedará marcada como anulada. El stock será devuelto automáticamente.')
                // 🛡️ LÓGICA: Solo si SÍ es NV y NO está anulado ya
                ->visible(fn ($record) => str_starts_with($record->serie, 'NV') && $record->sunat_status !== 'anulado')
                ->action(function ($record) {
                    $res = \App\Services\SaleAnulacionService::anularVenta($record, ['motivo_texto' => 'Anulación interna'], auth()->user());

                    if (!isset($res['success']) || $res['success'] !== true) {
                        Notification::make()
                            ->title('Error al anular')
                            ->body($res['error'] ?? 'No se pudo anular la venta.')
                            ->danger()
                            ->send();
                        return;
                    }

                    Notification::make()
                        ->title($res['title'] ?? 'Venta Interna Anulada')
                        ->body($res['message'])
                        ->success()
                        ->send();
                }),

            // 🔴 BOTÓN 4: ANULAR VENTA PENDIENTE (boleta/factura aún no declarada)
            Actions\Action::make('anular_pendiente')
                ->label('Anular Venta (Pendiente)')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Anular Venta Pendiente')
                ->modalDescription('Se consultará y verificará con SUNAT antes de anular. Si ya fue aceptada, se emitirá Nota de Crédito. Esta acción es irreversible.')
                // Visible solo si es boleta/factura pendiente (NO es NV, NO está anulado ni aceptado)
                ->visible(fn ($record) => !str_starts_with($record->serie, 'NV') && $record->sunat_status === 'pendiente')
                ->action(function ($record) {
                    $res = \App\Services\SaleAnulacionService::anularVenta($record, [
                        'codigo_motivo' => '01',
                        'motivo_texto' => 'Anulación de venta pendiente'
                    ], auth()->user());

                    if (!isset($res['success']) || $res['success'] !== true) {
                        Notification::make()
                            ->title('Error al anular')
                            ->body($res['error'] ?? 'No se pudo procesar la anulación con SUNAT.')
                            ->danger()
                            ->persistent()
                            ->send();
                        return;
                    }

                    Notification::make()
                        ->title($res['title'] ?? 'Venta Anulada')
                        ->body($res['message'])
                        ->success()
                        ->send();
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
                    auth()->user()?->seesAllBranches() &&
                    !str_starts_with($record->serie, 'NV') &&
                    in_array($record->sunat_status, ['rechazado', 'pendiente']) &&
                    (!$record->is_quick_sale || $record->is_audited)
                )
                ->action(function (Sale $record) {
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

            // 🔴 BOTÓN 5: REGULARIZAR NOTA DE CRÉDITO (comprobantes anulados "Sin nota de crédito")
            Actions\Action::make('regularizar_nc')
                ->label('Regularizar Nota de Crédito')
                ->icon('heroicon-o-arrow-path-rounded-square')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Regularizar Nota de Crédito en SUNAT')
                ->modalDescription('Esta venta figura anulada en el sistema pero NO tiene Nota de Crédito en SUNAT. Se emitirá la Nota de Crédito oficial ante SUNAT para anular tributariamente el comprobante. El inventario NO se devolverá de nuevo porque ya fue devuelto en la anulación previa.')
                ->form([
                    \Filament\Forms\Components\Select::make('codigo_motivo')
                        ->label('Motivo Oficial SUNAT')
                        ->options([
                            '01' => 'Anulación de la operación',
                            '02' => 'Anulación por error en el RUC/DNI',
                            '06' => 'Devolución total',
                        ])
                        ->default('01')
                        ->required(),
                    \Filament\Forms\Components\Textarea::make('motivo_texto')
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
                    $res = \App\Services\SaleAnulacionService::regularizarNotaCredito($record, $data, auth()->user());

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
                            \Filament\Notifications\Actions\Action::make('imprimir_ticket')
                                ->label('Imprimir Ticket')
                                ->icon('heroicon-o-printer')
                                ->color('success')
                                ->url(route('sales.notes.ticket', ['sale' => $record->id, 'tipo' => 'credito']), shouldOpenInNewTab: true),
                            \Filament\Notifications\Actions\Action::make('imprimir_a4')
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