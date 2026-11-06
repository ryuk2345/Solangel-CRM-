<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditSaleResource\Pages;
use App\Models\Sale;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class AuditSaleResource extends Resource
{
    protected static ?string $model = \App\Models\AuditSale::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-check';
    protected static ?string $navigationGroup = 'Administración';
    protected static ?int $navigationSort = 4;
    protected static ?string $modelLabel = 'Auditoría de Venta';

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('customer.name')
                    ->label('Cliente')
                    ->searchable(query: function (\Illuminate\Database\Eloquent\Builder $query, string $search): \Illuminate\Database\Eloquent\Builder {
                        $search = trim($search);
                        return $query->whereHas('customer', function (\Illuminate\Database\Eloquent\Builder $q) use ($search) {
                            $q->where(function (\Illuminate\Database\Eloquent\Builder $sub) use ($search) {
                                $sub->where('name', 'like', "%{$search}%")
                                    ->orWhere('document_number', 'like', "{$search}%");
                            });
                        });
                    })
                    ->limit(25)
                    ->description(fn(Sale $record): string => "Doc: " . ($record->customer?->document_number ?? '---')),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Cajero')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('gray')
                    ->icon('heroicon-m-user'),

                Tables\Columns\TextColumn::make('serie')
                    ->label('Comprobante')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn(string $state, Sale $record) => $state . '-' . $record->correlative)
                    ->badge()
                    ->color(fn(string $state): string => str_starts_with($state, 'F') ? 'info' : (str_starts_with($state, 'B') ? 'success' : 'gray')),

                Tables\Columns\TextColumn::make('issue_date')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total')
                    ->label('Total')
                    ->money('PEN')
                    ->alignment('right')
                    ->description(function (Sale $record): string {
                        $pagos = $record->payments;
                        if ($pagos->isNotEmpty()) {
                            if ($pagos->count() === 1) {
                                return ucfirst(strtolower($pagos->first()->payment_method ?? 'Efectivo'));
                            }
                            return $pagos->map(fn($p) => ucfirst(strtolower($p->payment_method)))->unique()->join(' / ');
                        }
                        return ucfirst(strtolower($record->payment_method ?? 'Efectivo'));
                    }),

                Tables\Columns\TextColumn::make('sunat_status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'pendiente' => 'warning',
                        'aceptado'  => 'success',
                        'anulado'   => 'danger',
                        'rechazado' => 'danger',
                        default     => 'gray',
                    })
                    ->description(fn (Sale $record): ?string => $record->sunat_status === 'rechazado' && $record->sunat_error_code ? "Error {$record->sunat_error_code}" : null),

                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Sucursal')
                    ->badge()
                    ->color('info')
                    ->sortable()
                    ->searchable()
                    ->visible(fn () => auth()->user()?->seesAllBranches() ?? false),

                Tables\Columns\TextColumn::make('warehouse.name')
                    ->label('Almacén')
                    ->badge()
                    ->color('warning')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: false),
            ])
            ->defaultSort('created_at', 'desc')

            // 🌟 FILTROS AVANZADOS 🌟
            ->filters([
                // 1. Filtro por Rango de Fechas
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('desde')->label('Desde'),
                        Forms\Components\DatePicker::make('hasta')->label('Hasta'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['desde'], fn($q) => $q->whereDate('created_at', '>=', $data['desde']))
                            ->when($data['hasta'], fn($q) => $q->whereDate('created_at', '<=', $data['hasta']));
                    }),

                // 2. Filtro por Estado de SUNAT
                Tables\Filters\SelectFilter::make('sunat_status')
                    ->label('Estado SUNAT')
                    ->options([
                        'aceptado'  => '✅ Aceptado',
                        'pendiente' => '⏳ Pendiente',
                        'rechazado' => '❌ Rechazado',
                        'anulado'   => '🚫 Anulado',
                    ]),

                // 3. Filtro por Sucursal (super_admin)
                Tables\Filters\SelectFilter::make('branch_id')
                    ->relationship('branch', 'name')
                    ->label('Sucursal')
                    ->searchable()
                    ->preload()
                    ->visible(fn () => auth()->user()?->seesAllBranches() ?? false),

                // 4. Filtro por Almacén
                Tables\Filters\SelectFilter::make('warehouse_id')
                    ->relationship('warehouse', 'name', function (Builder $query) {
                        if (!auth()->user()?->seesAllBranches()) {
                            $query->where('branch_id', auth()->user()?->branch_id ?? 1);
                        }
                        return $query->where('is_active', true);
                    })
                    ->label('Almacén')
                    ->searchable()
                    ->preload(),
            ])

            ->actions([
                // 📋 Un solo botón ⋮ con todas las acciones
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('imprimir')
                        ->label('Imprimir Ticket')
                        ->icon('heroicon-o-printer')
                        ->color('success')
                        ->url(fn(Sale $record): string => route('sales.ticket', $record->id))
                        ->openUrlInNewTab()
                        ->visible(
                            fn(Sale $record) =>
                            $record->sunat_status === 'aceptado' ||
                                str_starts_with($record->serie, 'NV') // Siempre visible para Notas de Venta
                        ),

                    Tables\Actions\ViewAction::make()
                        ->label('Ver Detalles')
                        ->icon('heroicon-o-eye')
                        ->color('gray'),

                    Tables\Actions\Action::make('imprimir_a4')
                        ->label('Imprimir A4')
                        ->icon('heroicon-o-document-text')
                        ->color('info')
                        ->url(fn(Sale $record): string => route('sales.a4', $record->id))
                        ->openUrlInNewTab()
                        ->visible(
                            fn(Sale $record) =>
                            $record->sunat_status === 'aceptado' ||
                                str_starts_with($record->serie, 'NV') // Siempre visible para Notas de Venta
                        ),

                    Tables\Actions\Action::make('descargar_xml')
                        ->label('Descargar XML')
                        ->icon('heroicon-o-code-bracket')
                        ->color('gray')
                        ->visible(fn(Sale $record) => $record->sunat_status === 'aceptado' && !str_starts_with($record->serie, 'NV'))
                        ->action(function (Sale $record) {
                            $ruc = \App\Models\CompanySetting::first()?->ruc ?? env('EMPRESA_RUC');
                            $tipoDoc = str_starts_with($record->serie, 'F') ? '01' : '03';
                            $correlativoPadded = str_pad($record->correlative, 8, '0', STR_PAD_LEFT);
                            $nombreArchivo = "{$ruc}-{$tipoDoc}-{$record->serie}-{$correlativoPadded}";
                            $ruta = storage_path("app/sunat/xmls/{$nombreArchivo}.xml");
                            if (file_exists($ruta)) return response()->download($ruta);
                        }),

                    Tables\Actions\Action::make('descargar_cdr')
                        ->label('Descargar CDR')
                        ->icon('heroicon-o-archive-box-arrow-down')
                        ->color('success')
                        ->visible(fn(Sale $record) => $record->sunat_status === 'aceptado' && !str_starts_with($record->serie, 'NV'))
                        ->action(function (Sale $record) {
                            $ruc = \App\Models\CompanySetting::first()?->ruc ?? env('EMPRESA_RUC');
                            $tipoDoc = str_starts_with($record->serie, 'F') ? '01' : '03';
                            $correlativoPadded = str_pad($record->correlative, 8, '0', STR_PAD_LEFT);
                            $nombreArchivo = "{$ruc}-{$tipoDoc}-{$record->serie}-{$correlativoPadded}";
                            $ruta = storage_path("app/sunat/cdrs/R-{$nombreArchivo}.zip");
                            if (file_exists($ruta)) return response()->download($ruta);
                        }),

                    Tables\Actions\Action::make('descargar_cdr_nc')
                        ->label('CDR Anulación')
                        ->icon('heroicon-o-shield-exclamation') // Un escudo de alerta
                        ->color('danger')
                        ->visible(fn(Sale $record) => $record->sunat_status === 'anulado' && !str_starts_with($record->serie, 'NV'))
                        ->action(function (Sale $record) {
                            $ruc = \App\Models\CompanySetting::first()?->ruc ?? env('EMPRESA_RUC', '20123456789');
                            $letraNC = str_starts_with($record->serie, 'F') ? 'F' : 'B';
                            $serieNotaCredito = $letraNC . 'C01';
                            $correlativoNCPadded = str_pad($record->id, 8, '0', STR_PAD_LEFT);

                            if (!empty($record->credit_note_serie) && !empty($record->credit_note_correlative)) {
                                $ncPad = str_pad($record->credit_note_correlative, 8, '0', STR_PAD_LEFT);
                                $directCdr = storage_path("app/sunat/cdrs/R-{$ruc}-07-{$record->credit_note_serie}-{$ncPad}.zip");
                                if (file_exists($directCdr)) {
                                    return response()->download($directCdr);
                                }
                            }

                            $docAfectado = $record->serie . '-' . str_pad($record->correlative, 8, '0', STR_PAD_LEFT);
                            $archivosXml = glob(storage_path("app/sunat/xmls/{$ruc}-07-*.xml"));
                            if ($archivosXml) {
                                foreach ($archivosXml as $xmlFile) {
                                    $contenido = file_get_contents($xmlFile);
                                    if (str_contains($contenido, $docAfectado)) {
                                        $baseName = pathinfo($xmlFile, PATHINFO_FILENAME);
                                        $posibleCdr = storage_path("app/sunat/cdrs/R-{$baseName}.zip");
                                        if (file_exists($posibleCdr)) {
                                            return response()->download($posibleCdr);
                                        }
                                    }
                                }
                            }

                            $nombreArchivoNC = "{$ruc}-07-{$serieNotaCredito}-{$correlativoNCPadded}";
                            $ruta = storage_path("app/sunat/cdrs/R-{$nombreArchivoNC}.zip");

                            if (file_exists($ruta)) {
                                return response()->download($ruta);
                            }

                            Notification::make()
                                ->title('Archivo no encontrado')
                                ->body("No se encontró el CDR de la anulación para {$docAfectado}")
                                ->warning()
                                ->send();
                        }),

                    Tables\Actions\Action::make('declarar_sunat')
                        ->label('Declarar a SUNAT')
                        ->icon('heroicon-o-cloud-arrow-up')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->hidden(
                            fn(Sale $record) =>
                            $record->sunat_status !== 'pendiente' ||
                            str_starts_with($record->serie, 'NV')
                        )
                        ->action(function (Sale $record) {
                            $service = new \App\Services\SunatService();
                            $res = $service->enviarBoleta($record, isRetry: true);
                            if (isset($res['success'])) {
                                $record->update([
                                    'sunat_status'     => 'aceptado',
                                    'sunat_error'      => null,
                                    'sunat_error_code' => null,
                                ]);
                                Notification::make()->title('¡Documento Aceptado!')->success()->send();
                            } elseif (!empty($res['rejected_by_sunat'])) {
                                $record->update([
                                    'sunat_status'     => 'rechazado',
                                    'sunat_error'      => $res['error'] ?? 'Rechazado por SUNAT',
                                    'sunat_error_code' => $res['code'] ?? null,
                                ]);
                                Notification::make()
                                    ->title('Comprobante Rechazado por SUNAT')
                                    ->body(($res['error'] ?? 'Rechazado') . '. Debe ser reemitido por un administrador.')
                                    ->danger()
                                    ->persistent()
                                    ->send();
                            } else {
                                $record->update([
                                    'sunat_error'      => $res['error'] ?? 'Ocurrió un error',
                                    'sunat_error_code' => $res['code'] ?? null,
                                ]);
                                Notification::make()->title('Error SUNAT')->body($res['error'] ?? 'Ocurrió un error')->danger()->send();
                            }
                        }),

                    Tables\Actions\Action::make('reemitir_comprobante')
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
                        ->visible(fn (Sale $record) =>
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
                ])
                ->icon('heroicon-m-ellipsis-vertical')
                ->color('gray')
                ->tooltip('Más acciones'),
            ])

            // 🌟 ACCIONES MASIVAS 🌟
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('reemitir_lote')
                        ->label('Reemitir / Reintentar Seleccionados')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Reemisión y Reintento Masivo a SUNAT')
                        ->modalDescription('Se procesarán los comprobantes seleccionados que estén pendientes o rechazados. Los pendientes se reintentarán primero; si SUNAT los rechaza, se reemitirán con número nuevo. Los rechazados se reemitirán directamente con número nuevo y fecha de hoy. Stock y caja no se modificarán.')
                        ->modalSubmitActionLabel('Iniciar Procesamiento Masivo')
                        ->visible(fn () => auth()->user()?->seesAllBranches())
                        ->action(function (Collection $records) {
                            $filtrados = $records->filter(fn (Sale $r) =>
                                !str_starts_with($r->serie, 'NV') &&
                                in_array($r->sunat_status, ['rechazado', 'pendiente']) &&
                                (!$r->is_quick_sale || $r->is_audited)
                            );

                            if ($filtrados->isEmpty()) {
                                Notification::make()
                                    ->title('Sin comprobantes aptos')
                                    ->body('Ninguno de los registros seleccionados califica para reemisión o reintento.')
                                    ->warning()
                                    ->send();
                                return;
                            }

                            $service = new \App\Services\SaleReemissionService();
                            $res = $service->reemitirLote($filtrados, auth()->user());

                            $msg = "Total procesados: {$res['total']}. "
                                . "Aceptados sin reemitir: " . ($res['marked_accepted'] + $res['retry_accepted']) . ". "
                                . "Reemitidos y Aceptados: {$res['reemitted_accepted']}. "
                                . "Reemitidos pendientes (reintento red): {$res['reemitted_pending']}. "
                                . "Rechazados: {$res['reemitted_rejected']}. "
                                . "Fallidos: {$res['failed']}.";

                            Notification::make()
                                ->title('Proceso Masivo Finalizado')
                                ->body($msg)
                                ->persistent()
                                ->success()
                                ->send();
                        }),

                    // Descargar todos los XMLs seleccionados en un solo ZIP
                    Tables\Actions\BulkAction::make('descargar_xml_masivo')
                        ->label('Descargar XMLs (ZIP)')
                        ->icon('heroicon-o-archive-box')
                        ->color('success')
                        ->action(function (Collection $records) {
                            $zip = new \ZipArchive;
                            $fileName = 'XMLS_BOUTIQUE_' . now()->format('Ymd_His') . '.zip';
                            $zipPath = storage_path('app/public/' . $fileName);

                            if ($zip->open($zipPath, \ZipArchive::CREATE) === TRUE) {
                                foreach ($records as $record) {
                                    $ruc = \App\Models\CompanySetting::first()?->ruc ?? env('EMPRESA_RUC');
                                    $tipoDoc = str_starts_with($record->serie, 'F') ? '01' : '03';
                                    $correlativoPadded = str_pad($record->correlative, 8, '0', STR_PAD_LEFT);
                                    $nombreArchivo = "{$ruc}-{$tipoDoc}-{$record->serie}-{$correlativoPadded}.xml";
                                    $rutaOriginal = storage_path("app/sunat/xmls/{$nombreArchivo}");

                                    if (file_exists($rutaOriginal)) {
                                        $zip->addFile($rutaOriginal, $nombreArchivo);
                                    }
                                }
                                $zip->close();
                                return response()->download($zipPath)->deleteFileAfterSend(true);
                            }
                        }),
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function infolist(\Filament\Infolists\Infolist $infolist): \Filament\Infolists\Infolist
    {
        return $infolist
            ->schema([
                \Filament\Infolists\Components\Section::make('Información del Documento')
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('customer.name')->label('Cliente'),
                        \Filament\Infolists\Components\TextEntry::make('customer.document_number')->label('Doc. Cliente'),
                        \Filament\Infolists\Components\TextEntry::make('serie')
                            ->label('N° Comprobante')
                            ->formatStateUsing(fn($state, $record) => $state . '-' . $record->correlative),
                        \Filament\Infolists\Components\TextEntry::make('issue_date')->label('Fecha Emisión')->date('d/m/Y'),
                        \Filament\Infolists\Components\TextEntry::make('sunat_status')
                            ->label('Estado SUNAT')
                            ->badge()
                            ->color(fn(string $state): string => match ($state) {
                                'pendiente' => 'warning',
                                'aceptado'  => 'success',
                                'anulado'   => 'danger',
                                'rechazado' => 'danger',
                                default     => 'gray',
                            }),
                    ])->columns(3),

                \Filament\Infolists\Components\Section::make('Ropa Comprada')
                    ->schema([
                        \Filament\Infolists\Components\RepeatableEntry::make('items')
                            ->label('')
                            ->schema([
                                \Filament\Infolists\Components\TextEntry::make('product_name')->label('Prenda'),
                                \Filament\Infolists\Components\TextEntry::make('quantity')->label('Cantidad'),
                                \Filament\Infolists\Components\TextEntry::make('price')->label('Precio Unit.')->money('PEN'),
                                \Filament\Infolists\Components\TextEntry::make('total')->label('Subtotal')->money('PEN'),
                            ])->columns(4)
                    ]),

                \Filament\Infolists\Components\Section::make('Resumen Financiero')
                    ->schema([
                        \Filament\Infolists\Components\TextEntry::make('payment_method')->label('Pagó con'),
                        \Filament\Infolists\Components\TextEntry::make('amount_paid')->label('Recibido')->money('PEN'),
                        \Filament\Infolists\Components\TextEntry::make('balance_returned')->label('Vuelto')->money('PEN'),
                        \Filament\Infolists\Components\TextEntry::make('total')
                            ->label('TOTAL PAGADO')
                            ->money('PEN')
                            ->badge()
                            ->color('success'),
                    ])->columns(4),

                \Filament\Infolists\Components\Section::make('Historial de Reemisiones SUNAT')
                    ->description('Comprobantes anteriores emitidos para esta venta y rechazados por SUNAT')
                    ->collapsible()
                    ->visible(fn (?Sale $record) => !empty($record?->reemission_history))
                    ->schema([
                        \Filament\Infolists\Components\RepeatableEntry::make('reemission_history')
                            ->label('')
                            ->schema([
                                \Filament\Infolists\Components\TextEntry::make('serie')
                                    ->label('Serie'),
                                \Filament\Infolists\Components\TextEntry::make('correlative')
                                    ->label('Correlativo'),
                                \Filament\Infolists\Components\TextEntry::make('sunat_error_code')
                                    ->label('Cód. Error SUNAT')
                                    ->badge()
                                    ->color('danger'),
                                \Filament\Infolists\Components\TextEntry::make('sunat_error')
                                    ->label('Motivo Rechazo SUNAT'),
                                \Filament\Infolists\Components\TextEntry::make('reemitted_at')
                                    ->label('Fecha Reemisión'),
                                \Filament\Infolists\Components\TextEntry::make('reemitted_by_name')
                                    ->label('Reemitido Por'),
                            ])->columns(6)
                    ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user && !$user->seesAllBranches()) {
            $query->where('branch_id', $user->branch_id ?? 1);
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditSales::route('/'),
            'view' => Pages\ViewAuditSale::route('/{record}'),
        ];
    }
}
