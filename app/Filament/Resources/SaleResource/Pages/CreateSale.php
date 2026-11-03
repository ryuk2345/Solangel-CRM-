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

    /**
     * Toda la creación (correlativo, venta, detalle, stock, pagos y caja) va en una transacción:
     * si falta stock al descontar, no queda nada a medias. SUNAT se envía después del commit.
     */
    protected ?bool $hasDatabaseTransactions = true;

    // 🌟 1. EL BLOQUEO INICIAL: Se ejecuta antes de cargar la pantalla
    public function mount(): void
    {
        parent::mount();
        $this->precargarCambio();
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

    /** /admin/sales/create?cambio=ID: venta nueva de un cambio de talla, con el saldo a favor. */
    private function precargarCambio(): void
    {
        $returnId = (int) request()->query('cambio');
        if (!$returnId) {
            return;
        }

        $return = \App\Models\SaleReturn::with('sale')->find($returnId);
        if (!$return || $return->available_credit <= 0) {
            Notification::make()->title('Ese cambio ya no tiene saldo a favor')->warning()->send();

            return;
        }

        $this->data['customer_id'] = $return->sale->customer_id;
        $this->data['branch_id'] = $return->branch_id;
        $this->data['warehouse_id'] = $return->warehouse_id;
        $this->data['exchange_return_id'] = $return->id;
        $this->data['items'] = [];
        $this->data['payments'] = [['payment_method' => 'efectivo', 'amount' => 0]];
    }

    // 2. ANTES DE GUARDAR: Validamos sucursal y asignamos número con candado
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // ─── 1. PRECIOS CALCULADOS EN EL SERVIDOR (regla minorista/mayorista) ───
        // Lo que manda el formulario no se cree: se vuelve a calcular todo (PLAN_MODA H1/H2).
        $totalVenta = 0.0;
        if (!empty($data['is_quick_sale'])) {
            Notification::make()->title('La venta rápida fue retirada')->body('Registra la venta con sus prendas.')->danger()->send();
            $this->halt();
        }

        $items = collect($this->data['items'] ?? [])
            ->filter(fn ($i) => !empty($i['product_variant_id']))
            ->all();

        if (empty($items)) {
            Notification::make()->title('Agrega al menos una prenda')->danger()->send();
            $this->halt();
        }

        $warehouseId = \App\Support\SaleCart::warehouseFor($data['branch_id'] ?? null, $data['warehouse_id'] ?? null);
        $data['warehouse_id'] = $warehouseId;

        $requestId = $this->data['wholesale_request_id'] ?? null;
        $pendingRequest = $requestId ? \App\Models\WholesalePriceRequest::find($requestId) : null;
        if ($pendingRequest && $pendingRequest->status === 'pendiente') {
            Notification::make()
                ->title('Hay una aprobación pendiente')
                ->body('Espera a que el administrador apruebe o rechace el precio mayorista, o pide que lo apruebe aquí con su PIN.')
                ->warning()->persistent()->send();
            $this->halt();
        }

        // Cambio de talla: saldo a favor de la devolución y modo de precio de la venta original
        $exchangeReturn = !empty($this->data['exchange_return_id']) ? \App\Models\SaleReturn::with('sale')->find($this->data['exchange_return_id']) : null;
        $credit = $exchangeReturn ? (float) $exchangeReturn->available_credit : 0.0;
        if ($exchangeReturn && $credit <= 0) {
            Notification::make()->title('El saldo a favor de este cambio ya se usó')->danger()->persistent()->send();
            $this->halt();
        }
        if ($exchangeReturn && (int) $exchangeReturn->sale?->customer_id !== (int) ($data['customer_id'] ?? 0)) {
            Notification::make()->title('El cambio debe hacerse al mismo cliente de la venta original')->danger()->send();
            $this->halt();
        }

        $pricing = \App\Services\PricingService::apply(
            $items,
            isset($data['customer_id']) ? (int) $data['customer_id'] : null,
            isset($data['branch_id']) ? (int) $data['branch_id'] : null,
            $requestId ? (int) $requestId : null,
            Auth::user(),
            $exchangeReturn?->sale?->price_mode,
        );

        // Delivery solo si alguna prenda lo permite (decisión 4)
        $hasService = collect($pricing['lines'])->contains(fn ($l) => ($l['price_type'] ?? null) === 'service');
        if ($hasService && !\App\Support\SaleCart::allowsDelivery($items)) {
            Notification::make()->title('Delivery no disponible')
                ->body('Ninguna prenda de esta venta permite delivery.')->danger()->persistent()->send();
            $this->halt();
        }
        if ($pricing['garments'] < 1) {
            Notification::make()->title('La venta debe tener al menos una prenda')->danger()->send();
            $this->halt();
        }

        // Sin stock negativo (decisión 5): se revisa antes y se vuelve a revisar al descontar con bloqueo
        $faltantes = \App\Services\StockService::shortages($pricing['lines'], (int) $warehouseId);
        if (!empty($faltantes)) {
            Notification::make()
                ->title('Stock insuficiente')
                ->body(implode("\n", $faltantes))
                ->danger()->persistent()->send();
            $this->halt();
        }

        foreach ($pricing['lines'] as $key => $line) {
            $pricing['lines'][$key]['price_set_by'] = ($line['price_reason'] ?? null) === 'manual' ? Auth::id() : null;
        }

        $this->data['items'] = $pricing['lines'];
        $data['price_mode'] = $pricing['mode'];
        $data['wholesale_request_id'] = $pricing['reason'] === 'authorized' ? (int) $requestId : null;
        $data['exchange_return_id'] = $exchangeReturn?->id;
        $totalVenta = $pricing['total'];

        if ($exchangeReturn && $totalVenta + 0.001 < $credit) {
            Notification::make()
                ->title('El cambio debe ser por un monto igual o mayor')
                ->body('El saldo a favor es S/ ' . number_format($credit, 2) . ' y la venta suma S/ ' . number_format($totalVenta, 2)
                    . '. Agrega otra prenda, o pide al administrador una devolución de dinero.')
                ->danger()->persistent()->send();
            $this->halt();
        }

        // ─── 1.5. BLOQUEAR LÍNEAS EN PRECIO 0 PARA BOLETAS Y FACTURAS ───
        $esElectronico = !str_starts_with(trim((string)($data['serie'] ?? '')), 'NV');
        if ($esElectronico) {
            if (!empty($data['is_quick_sale'])) {
                if ($totalVenta <= 0) {
                    Notification::make()
                        ->title('Línea con precio 0 no permitida')
                        ->body('Las boletas y facturas no admiten líneas con precio 0. Revisa los precios del modelo.')
                        ->danger()
                        ->persistent()
                        ->send();
                    $this->halt();
                }
            } else {
                $itemsCheck = $this->data['items'] ?? [];
                foreach ($itemsCheck as $item) {
                    $precio = (float)($item['price'] ?? 0);
                    $cant = (float)($item['quantity'] ?? 1);
                    $totalLinea = (isset($item['total']) && is_numeric($item['total']))
                        ? (float)$item['total']
                        : ($precio * $cant);

                    if ($precio <= 0 || $totalLinea <= 0) {
                        Notification::make()
                            ->title('Línea con precio 0 no permitida')
                            ->body('Las boletas y facturas no admiten líneas con precio 0. Revisa los precios del modelo.')
                            ->danger()
                            ->persistent()
                            ->send();
                        $this->halt();
                    }
                }
            }
        }

        // ─── 2. VALIDAR Y NORMALIZAR DESGLOSE DE PAGOS (Antes del candado) ───
        // En un cambio, el saldo a favor paga primero; el resto se cobra con los medios elegidos
        $rawPayments = collect($this->data['payments'] ?? $data['payments'] ?? [])
            ->reject(fn ($p) => ($p['payment_method'] ?? null) === 'nota_credito');
        $creditApplied = round(min($credit, $totalVenta), 2);
        $totalVentaOriginal = $totalVenta;
        $totalVenta = round($totalVenta - $creditApplied, 2);

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

        if ($creditApplied > 0) {
            $this->data['payments'] = collect($this->data['payments'])
                ->filter(fn ($p) => (float) ($p['amount'] ?? 0) > 0)
                ->prepend(['payment_method' => 'nota_credito', 'amount' => $creditApplied])
                ->values()->toArray();
            $data['payment_method'] = count($this->data['payments']) > 1 ? 'múltiple' : 'nota_credito';
        }
        $totalVenta = $totalVentaOriginal;

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

    // 3. DESPUÉS DE GUARDAR (todavía dentro de la transacción)
    protected function afterCreate(): void
    {
        $venta = $this->record;

        // ── 1. STOCK: descuento con bloqueo y kardex. Si falta stock, se deshace toda la venta. ──
        try {
            $venta->load('items.productVariant.product');
            foreach ($venta->items as $item) {
                if (!$item->productVariant || $item->productVariant->product?->is_service) {
                    continue;
                }
                \App\Services\StockService::move(
                    (int) $item->product_variant_id,
                    (int) $venta->warehouse_id,
                    -1 * (int) $item->quantity,
                    'sale',
                    $venta,
                    "Venta {$venta->serie}-{$venta->correlative}",
                );
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            Notification::make()
                ->title('Stock insuficiente: la venta no se guardó')
                ->body(collect($e->errors())->flatten()->implode("\n"))
                ->danger()->persistent()->send();
            $this->halt(shouldRollbackDatabaseTransaction: true);
        }

        // ── 2. Totales desde el detalle guardado ──
        $totalReal = round((float) $venta->items()->sum('total'), 2);
        $subtotal = round($totalReal / 1.18, 2);
        $venta->update(['total' => $totalReal, 'subtotal' => $subtotal, 'igv' => round($totalReal - $subtotal, 2)]);

        // ── 3. La aprobación de precio mayorista queda usada por esta venta ──
        \App\Services\WholesaleApprovalService::markUsed($venta->wholesale_request_id, $venta);

        // ── 3b. Cambio de talla: el saldo a favor queda usado por esta venta (una sola vez) ──
        if ($venta->exchange_return_id) {
            try {
                \App\Services\SaleExchangeService::linkNewSale((int) $venta->exchange_return_id, $venta);
            } catch (\Illuminate\Validation\ValidationException $e) {
                Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->persistent()->send();
                $this->halt(shouldRollbackDatabaseTransaction: true);
            }
        }

        // ── 4. Pagos y caja ──
        $this->sincronizarPagosYCaja($venta);

        // ── 5. SUNAT, recién cuando la venta quedó guardada (fuera de la transacción) ──
        DB::afterCommit(function () use ($venta) {
            try {
                $this->enviarSunat($venta->fresh());
            } catch (\Throwable $e) {
                Log::error('[CreateSale] Error después de guardar: ' . $e->getMessage(), ['sale_id' => $venta->id]);
                Notification::make()
                    ->title('Venta guardada ✓')
                    ->body('Se guardó correctamente, pero hubo un error interno: ' . $e->getMessage())
                    ->warning()->send();
            }
        });
    }

    private function sincronizarPagosYCaja(\App\Models\Sale $venta): void
    {
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

        // Caja: sumar exactamente el efectivo guardado en sale_payments
        $montoEfectivo = (float) $venta->payments()
            ->where('payment_method', 'efectivo')
            ->sum('amount');

        if ($montoEfectivo > 0 && $this->cajaValidaId) {
            $caja = CashRegister::whereKey($this->cajaValidaId)->lockForUpdate()->first();
            if ($caja && $caja->status === 'abierta') {
                $caja->increment('cash_sales', $montoEfectivo);
            }
        }
    }

    private function enviarSunat(\App\Models\Sale $venta): void
    {
        if (str_starts_with($venta->serie, 'NV')) {
            $venta->update(['sunat_status' => 'aceptado']);
            return;
        }

        try {
            $service = app(\App\Services\SunatService::class);
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
