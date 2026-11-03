<?php

namespace App\Filament\Resources\SaleResource\Pages;

use App\Filament\Resources\SaleResource;
use App\Models\CashRegister;
use App\Models\Inventory;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateSale extends CreateRecord
{
    protected static string $resource = SaleResource::class;
    protected ?int $cajaValidaId = null;

    // 🌟 1. EL BLOQUEO INICIAL: Se ejecuta antes de cargar la pantalla
    public function mount(): void
    {
        parent::mount();
        $user = Auth::user();
        $hoyLima = now()->setTimezone('America/Lima')->startOfDay();

        if ($user && $user->seesAllBranches()) {
            $hayCajasHoy = CashRegister::where('status', 'abierta')
                ->where('opened_at', '>=', $hoyLima)
                ->exists();

            if (!$hayCajasHoy) {
                Notification::make()
                    ->title('Sin cajas abiertas')
                    ->body('No hay cajas abiertas hoy. Pide al cajero que abra la caja de la tienda.')
                    ->danger()
                    ->send();

                $this->redirect(\App\Filament\Resources\CashRegisterResource::getUrl('index'));
                return;
            }
        } else {
            $cajaAbierta = CashRegister::where('user_id', Auth::id())
                ->where('status', 'abierta')
                ->latest('opened_at')
                ->first();

            if (!$cajaAbierta) {
                Notification::make()
                    ->title('¡Alto ahí!')
                    ->body('Debes abrir tu caja antes de poder registrar una venta.')
                    ->danger()
                    ->send();

                $this->redirect(\App\Filament\Resources\CashRegisterResource::getUrl('index'));
                return;
            }

            $fechaAperturaLima = \Carbon\Carbon::parse($cajaAbierta->opened_at)->setTimezone('America/Lima');
            if ($fechaAperturaLima->lt($hoyLima)) {
                $fechaFormato = $fechaAperturaLima->format('d/m/Y');
                Notification::make()
                    ->title('Caja de día anterior')
                    ->body("Tu caja está abierta desde el {$fechaFormato}. Ciérrala y abre la caja de hoy para poder vender.")
                    ->danger()
                    ->send();

                $this->redirect(\App\Filament\Resources\CashRegisterResource::getUrl('index'));
                return;
            }
        }
    }

    // 2. ANTES DE GUARDAR: Validamos sucursal y asignamos número con candado
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // ─── 1. CALCULAR TOTAL DE LA VENTA (Misma fórmula que sales.total) ───
        $totalVenta = 0.0;
        if (!empty($data['is_quick_sale'])) {
            $totalVenta = round((float)($data['quick_sale_total'] ?? $this->data['quick_sale_total'] ?? 0), 2);
        } else {
            $items = $this->data['items'] ?? $data['items'] ?? [];
            $totalVenta = round(collect($items)->sum(function ($item) {
                if (isset($item['total']) && is_numeric($item['total'])) {
                    return (float)$item['total'];
                }
                $cant = (float)($item['quantity'] ?? 1);
                $precio = (float)($item['price'] ?? 0);
                return $cant * $precio;
            }), 2);
        }

        // ─── 1.5. BLOQUEAR LÍNEAS EN PRECIO 0 PARA BOLETAS Y FACTURAS ───
        $esElectronico = !str_starts_with(trim((string)($data['serie'] ?? '')), 'NV');
        if ($esElectronico) {
            if (!empty($data['is_quick_sale'])) {
                if ($totalVenta <= 0) {
                    Notification::make()
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
                        Notification::make()
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

        // ─── 2. VALIDAR Y NORMALIZAR DESGLOSE DE PAGOS (Antes del candado) ───
        $rawPayments = collect($this->data['payments'] ?? $data['payments'] ?? []);

        // En pagos mixtos, descartar filas con monto <= 0
        if ($rawPayments->count() > 1) {
            $rawPayments = $rawPayments->filter(fn($p) => (float)($p['amount'] ?? 0) > 0);
        }

        if ($rawPayments->count() <= 1) {
            // Pago único: asignar monto igual al total de la venta
            $primerPago = $rawPayments->first();
            $metodo = $primerPago['payment_method'] ?? 'efectivo';
            $key = $rawPayments->isNotEmpty() ? $rawPayments->keys()->first() : 0;

            $this->data['payments'] = [
                $key => [
                    'payment_method' => $metodo,
                    'amount'         => $totalVenta,
                ],
            ];
            $data['payment_method'] = $metodo;
        } else {
            // Pago mixto: deben sumar exactamente el total de la venta
            $sumaPagos = round($rawPayments->sum(fn($p) => (float)($p['amount'] ?? 0)), 2);

            if (abs($sumaPagos - $totalVenta) > 0.01) {
                Notification::make()
                    ->title('⚠️ Error en Desglose de Pagos Mixtos')
                    ->body("La suma de los pagos (S/ " . number_format($sumaPagos, 2) . ") debe ser exactamente igual al total de la venta (S/ " . number_format($totalVenta, 2) . ").")
                    ->danger()
                    ->send();
                $this->halt();
            }

            $this->data['payments'] = $rawPayments->values()->toArray();
            $data['payment_method'] = 'múltiple';
        }

        // Evitar duplicaciones por doble click (seguridad extra de 5 segundos para quien hace clic)
        $ultimaVentaReciente = \App\Models\Sale::where(function ($q) {
                $q->where('user_id', Auth::id())
                  ->orWhere('seller_id', Auth::id());
            })
            ->where('branch_id', $data['branch_id'])
            ->where('serie', $data['serie'] ?? null)
            ->where('created_at', '>=', now()->subSeconds(5))
            ->first();

        if ($ultimaVentaReciente) {
            Notification::make()
                ->title('Comprobante Duplicado')
                ->body('Se detectó un intento de doble envío. Por favor espera a que cargue la página.')
                ->warning()
                ->send();
            $this->halt();
        }

        $user = Auth::user();
        $hoyLima = now()->setTimezone('America/Lima')->startOfDay();

        if ($user && $user->seesAllBranches()) {
            $cajaAbierta = CashRegister::getActiveForSuperAdmin($data['branch_id']);

            if (!$cajaAbierta) {
                Notification::make()
                    ->title('Sin cajas abiertas')
                    ->body('No hay cajas abiertas hoy en esta sucursal. Pide al cajero que abra la caja de la tienda.')
                    ->danger()
                    ->send();
                $this->halt();
            }

            $data['user_id'] = $cajaAbierta->user_id;
            $data['seller_id'] = !empty($data['seller_id']) ? $data['seller_id'] : $user->id;
        } else {
            $cajaAbierta = CashRegister::where('user_id', Auth::id())
                ->where('branch_id', $data['branch_id'])
                ->where('status', 'abierta')
                ->latest('opened_at')
                ->first();

            if (!$cajaAbierta) {
                Notification::make()
                    ->title('Caja no disponible')
                    ->body('No tienes una caja abierta en la sucursal seleccionada. Debes abrir una caja antes de poder registrar una venta.')
                    ->danger()
                    ->send();
                $this->halt();
            }

            $fechaAperturaLima = \Carbon\Carbon::parse($cajaAbierta->opened_at)->setTimezone('America/Lima');
            if ($fechaAperturaLima->lt($hoyLima)) {
                $fechaFormato = $fechaAperturaLima->format('d/m/Y');
                Notification::make()
                    ->title('Caja de día anterior')
                    ->body("Tu caja está abierta desde el {$fechaFormato}. Ciérrala y abre la caja de hoy para poder vender.")
                    ->danger()
                    ->send();
                $this->halt();
            }

            $data['user_id'] = Auth::id();
            $data['seller_id'] = !empty($data['seller_id']) ? $data['seller_id'] : Auth::id();
        }

        $this->cajaValidaId = $cajaAbierta->id;

        if (empty($data['warehouse_id'])) {
            $data['warehouse_id'] = \App\Models\Warehouse::where('branch_id', $data['branch_id'])->where('is_default', true)->value('id')
                ?? \App\Models\Warehouse::where('branch_id', $data['branch_id'])->where('is_active', true)->value('id')
                ?? 1;
        }

        $branch = \App\Models\Branch::find($data['branch_id']);
        $sunatCode = trim($branch?->sunat_code ?? '');
        $isPrincipal = (int)$data['branch_id'] === 1;
        $isElectronic = !str_starts_with($data['serie'], 'NV');

        if ($isElectronic && !$isPrincipal && (empty($sunatCode) || $sunatCode === '0000')) {
            Notification::make()
                ->title('Emisión SUNAT Bloqueada')
                ->body("La tienda '{$branch->name}' no tiene registrado un código de anexo SUNAT válido (distinto de 0000). Solo se pueden emitir Notas de Venta internas hasta configurar su código en Sucursales.")
                ->danger()
                ->persistent()
                ->send();
            $this->halt();
        }

        // Clasificador automático para SUNAT
        if (str_starts_with($data['serie'], 'F')) {
            $data['document_type'] = '01';
        } elseif (str_starts_with($data['serie'], 'B')) {
            $data['document_type'] = '03';
        } else {
            $data['document_type'] = '00';
        }

        $data['total']    = $totalVenta;
        $data['subtotal'] = round($totalVenta / 1.18, 2);
        $data['igv']      = round($totalVenta - $data['subtotal'], 2);
        if (isset($data['is_quick_sale']) && $data['is_quick_sale']) {
            $data['is_audited'] = false;
        }

        $numeroAsignado = null;

        // CANDADO ANTI-DUPLICADOS
        DB::transaction(function () use (&$data, &$numeroAsignado) {
            $serieActiva = \App\Models\Serie::where('serie', $data['serie'])
                ->where('branch_id', $data['branch_id'])
                ->where('is_active', true)
                ->whereIn('document_type', ['00', '01', '03'])
                ->lockForUpdate()
                ->first();

            if ($serieActiva) {
                $numeroAsignado = $serieActiva->current_number;
                $serieActiva->increment('current_number');
            }
        });

        if (!$numeroAsignado) {
            Notification::make()
                ->title('Serie no disponible')
                ->body("No se encontró una serie activa '{$data['serie']}' para la sucursal seleccionada. No se puede emitir el comprobante sin correlativo.")
                ->danger()
                ->send();
            $this->halt();
        }

        $data['correlative'] = $numeroAsignado;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    // Safety net: borrar ítems vacíos de ventas rápidas
    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        $record = parent::handleRecordCreation($data);

        if (!empty($data['is_quick_sale'])) {
            $record->items()->delete();
        }

        return $record;
    }

    // 3. DESPUÉS DE GUARDAR
    protected function afterCreate(): void
    {
        // ─── ENVOLTURA GLOBAL: ningún error aquí debe producir un 500 ───
        try {
            $this->procesarPostCreacion();
        } catch (\Throwable $e) {
            // El comprobante YA fue guardado. Solo notificamos el fallo interno.
            Log::error('[CreateSale] Error en afterCreate: ' . $e->getMessage(), [
                'sale_id' => $this->record?->id,
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);

            Notification::make()
                ->title('Venta guardada ✓')
                ->body('Se guardó correctamente, pero hubo un error interno: ' . $e->getMessage())
                ->warning()
                ->send();
        }
    }

    // Lógica real separada para poder capturar cualquier Throwable
    private function procesarPostCreacion(): void
    {
        $venta = $this->record;

        // ── 1. VENTA RÁPIDA vs NORMAL (Calcular totalReal) ──
        if ($venta->is_quick_sale) {
            $totalReal = (float)$venta->total;
        } else {
            $totalReal = 0;
            DB::transaction(function () use ($venta, &$totalReal) {
                foreach ($venta->items as $item) {
                    $totalReal += (float)($item->total ?? 0);

                    $variante = \App\Models\ProductVariant::with('product')
                        ->find($item->product_variant_id);

                    if ($variante) {
                        if ($variante->sku === 'REPE-GENERICO') continue;
                        if ($variante->product && $variante->product->is_service) continue;
                    }

                    $warehouseId = $venta->warehouse_id ?? $venta->branch?->defaultWarehouse?->id ?? 1;

                    $inventario = Inventory::where('product_variant_id', $item->product_variant_id)
                        ->where('warehouse_id', $warehouseId)
                        ->lockForUpdate()
                        ->first();

                    if (!$inventario) {
                        $inventario = Inventory::firstOrCreate(
                            [
                                'product_variant_id' => $item->product_variant_id,
                                'warehouse_id' => $warehouseId,
                            ],
                            [
                                'branch_id' => $venta->branch_id,
                                'stock' => 0,
                            ]
                        );
                        $inventario = Inventory::where('id', $inventario->id)->lockForUpdate()->first();
                    }

                    if ($inventario) {
                        $inventario->decrement('stock', (int)($item->quantity ?? 1));
                    }
                }

                $subtotal = round($totalReal / 1.18, 2);
                $igv      = round($totalReal - $subtotal, 2);

                $venta->update([
                    'total'    => $totalReal,
                    'subtotal' => $subtotal,
                    'igv'      => $igv,
                ]);
            });
        }

        // ── 2. SINCRONIZAR Y VERIFICAR PAGOS CONTRA sales.total ──
        $totalVentaFinal = (float) $venta->fresh()->total;
        $pagosRel = $venta->payments()->get();

        if ($pagosRel->count() <= 1) {
            $primerPago = $pagosRel->first();
            if ($primerPago) {
                if ((float)$primerPago->amount !== $totalVentaFinal) {
                    $primerPago->update(['amount' => $totalVentaFinal]);
                }
                $metodoReal = $primerPago->payment_method ?? 'efectivo';
            } else {
                $metodoReal = $venta->payment_method ?: 'efectivo';
                $venta->payments()->create([
                    'payment_method' => $metodoReal,
                    'amount'         => $totalVentaFinal,
                ]);
            }

            if ($venta->payment_method !== $metodoReal) {
                $venta->update(['payment_method' => $metodoReal]);
            }
        } else {
            // En pagos mixtos, eliminar filas con monto <= 0
            $pagosRel->filter(fn($p) => (float)$p->amount <= 0)->each->delete();
            $pagosValidos = $venta->payments()->get();

            if ($pagosValidos->count() > 1) {
                if ($venta->payment_method !== 'múltiple') {
                    $venta->update(['payment_method' => 'múltiple']);
                }
            } elseif ($pagosValidos->count() === 1) {
                $pagoUnico = $pagosValidos->first();
                $pagoUnico->update(['amount' => $totalVentaFinal]);
                if ($venta->payment_method !== $pagoUnico->payment_method) {
                    $venta->update(['payment_method' => $pagoUnico->payment_method]);
                }
            }
        }

        // ── 3. CAJA: Sumar exactamente el efectivo persistido en sale_payments ──
        $montoEfectivo = (float) $venta->payments()
            ->where('payment_method', 'efectivo')
            ->sum('amount');

        if ($montoEfectivo > 0 && $this->cajaValidaId) {
            $caja = CashRegister::find($this->cajaValidaId);
            if ($caja && $caja->status === 'abierta') {
                $caja->increment('cash_sales', $montoEfectivo);
            }
        }

        // ── 4. NOTIFICACIÓN VENTA RÁPIDA ──
        if ($venta->is_quick_sale) {
            $venta->update(['sunat_status' => 'pendiente']);

            Notification::make()
                ->title('⚡ Venta Rápida Registrada')
                ->body('Recuerda auditar los repuestos para descontar el stock y declarar a SUNAT.')
                ->warning()
                ->send();

            return;
        }

        // ── SUNAT ─────────────────────────────────────────────────────
        if (str_starts_with($venta->serie, 'NV')) {
            $venta->update(['sunat_status' => 'aceptado']);
            return;
        }

        try {
            $service = new \App\Services\SunatService();
            $res     = $service->enviarBoleta($venta);

            if (isset($res['success']) && $res['success'] === true) {
                $venta->update([
                    'sunat_status' => 'aceptado',
                    'sunat_error' => null,
                    'sunat_error_code' => null,
                ]);
                Notification::make()
                    ->title('¡Venta Aceptada por SUNAT!')
                    ->success()
                    ->send();
            } else {
                $isRejected = !empty($res['rejected_by_sunat']);
                $newStatus = $isRejected ? 'rechazado' : 'pendiente';
                $venta->update([
                    'sunat_status' => $newStatus,
                    'sunat_error' => $res['error'] ?? null,
                    'sunat_error_code' => $res['code'] ?? null,
                ]);

                Notification::make()
                    ->title($isRejected ? 'Venta guardada. Rechazada por SUNAT.' : 'Venta guardada. Hubo un error con SUNAT.')
                    ->body($res['error'] ?? 'Puedes revisarlo desde la lista de ventas.')
                    ->warning()
                    ->send();
            }
        } catch (\Throwable $e) {
            $venta->update([
                'sunat_status' => 'pendiente',
                'sunat_error' => 'Error de conexión: ' . $e->getMessage(),
            ]);
            Log::error('[CreateSale] Error SUNAT: ' . $e->getMessage());
            Notification::make()
                ->title('Venta guardada. Falló la conexión con SUNAT.')
                ->body('Error: ' . $e->getMessage())
                ->warning()
                ->send();
        }
    }
}