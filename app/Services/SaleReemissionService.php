<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Sale;
use App\Models\Serie;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SaleReemissionService
{
    /**
     * Procesa la reemisión o reintento de un comprobante (individual).
     *
     * Reglas aplicadas:
     * 1. Orden al reemitir:
     *    Primero se guarda en la venta el nuevo número, fecha de hoy y el historial anterior.
     *    Luego se envía a SUNAT. Si falla por conexión, la venta queda en "pendiente"
     *    con el nuevo número para reintentarse con ese mismo número.
     * 2. Pendientes: reintentar siempre primero:
     *    - Si SUNAT lo acepta -> 'aceptado'.
     *    - Si SUNAT responde 1033 (ya registrado) -> 'aceptado' sin duplicar ni reemitir.
     *    - Si SUNAT lo rechaza (1032 o códigos 2000-3999) -> se reemite con número nuevo y fecha de hoy.
     *    - Los comprobantes ya en 'rechazado' se reemiten directamente con número nuevo y fecha de hoy.
     * 3. Solo para super_admin.
     * 4. Stock, caja y created_at NO se modifican en ningún caso.
     * 5. Líneas con precio 0:
     *    - Si es producto con stock -> se bloquea y se deja sin reemitir.
     *    - Si es servicio / REPE-GENERICO -> pasa su texto al final de la línea anterior (" - texto"),
     *      valida que no supere 255 caracteres (si supera, deja sin reemitir), guarda todos los datos
     *      de la línea en 0 en reemission_history y elimina la línea en 0.
     */
    public function reemitirOReintentar(Sale $sale, User $user): array
    {
        // 1. Control de permisos (solo super_admin)
        if (!$user->seesAllBranches()) {
            return [
                'success' => false,
                'error'   => 'Solo los usuarios super_admin tienen permiso para reemitir comprobantes.',
            ];
        }

        // 2. Validaciones básicas del comprobante
        if (str_starts_with(trim($sale->serie), 'NV')) {
            return [
                'success' => false,
                'error'   => 'Las notas de venta son documentos internos y no se declaran ni reemiten ante SUNAT.',
            ];
        }

        if ($sale->sunat_status === 'aceptado') {
            return [
                'success' => false,
                'error'   => 'El comprobante ya fue aceptado por SUNAT.',
            ];
        }

        if ($sale->sunat_status === 'anulado') {
            return [
                'success' => false,
                'error'   => 'El comprobante se encuentra anulado y no puede ser reemitido.',
            ];
        }

        if ($sale->is_quick_sale && !$sale->is_audited) {
            return [
                'success' => false,
                'error'   => 'Esta venta rápida debe ser auditada antes de declararse o reemitirse a SUNAT.',
            ];
        }

        // 3. Validar código de anexo de la sucursal (recargando sucursal desde BD)
        $branch = Branch::find($sale->branch_id) ?? $sale->branch;
        $sale->setRelation('branch', $branch);
        $sunatCode = trim((string)($branch?->sunat_code ?? ''));
        $isPrincipal = !$branch || (int)$branch->id === 1;

        if (empty($sunatCode) || (!$isPrincipal && $sunatCode === '0000')) {
            return [
                'success' => false,
                'error'   => "La sucursal '{$branch?->name}' no tiene configurado un código de anexo SUNAT válido. Corrija el código antes de emitir o reemitir.",
            ];
        }

        // 3.5. Inspeccionar y procesar líneas con precio 0 antes de reintentar o reemitir
        $zeroCheck = $this->procesarLineasPrecioCero($sale, $user);
        if (!$zeroCheck['can_proceed']) {
            return [
                'success' => false,
                'action'  => $zeroCheck['action'] ?? 'zero_price_blocked',
                'error'   => $zeroCheck['error'],
            ];
        }

        $removedZeroItems = $zeroCheck['removed_zero_items'] ?? [];
        if (!empty($removedZeroItems)) {
            $sale->unsetRelation('items');
            $sale->load('items');
        }

        $sunatService = app(SunatService::class);

        // 4. Si el comprobante está en estado "pendiente", reintentar SIEMPRE primero
        if ($sale->sunat_status === 'pendiente') {
            $resRetry = $sunatService->enviarBoleta($sale, isRetry: true);

            // Caso A: Aceptado por SUNAT (o respuesta 1033 ya registrado)
            if (isset($resRetry['success']) && $resRetry['success'] === true) {
                $updateData = [
                    'sunat_status'     => 'aceptado',
                    'sunat_error'      => null,
                    'sunat_error_code' => null,
                ];

                // Si se fusionaron líneas en 0 y fue aceptado en el reintento con su número original, guardar constancia en el historial
                if (!empty($removedZeroItems)) {
                    $history = is_array($sale->reemission_history) ? $sale->reemission_history : [];
                    $history[] = [
                        'serie'              => $sale->serie,
                        'correlative'        => $sale->correlative,
                        'issue_date'         => $sale->issue_date instanceof \DateTimeInterface ? $sale->issue_date->format('Y-m-d H:i:s') : (string)$sale->issue_date,
                        'sunat_status'       => 'aceptado_en_reintento',
                        'sunat_error'        => null,
                        'sunat_error_code'   => null,
                        'reemitted_at'       => now()->format('Y-m-d H:i:s'),
                        'reemitted_by'       => $user->id,
                        'reemitted_by_name'  => $user->name,
                        'removed_zero_items' => $removedZeroItems,
                    ];
                    $updateData['reemission_history'] = $history;
                }

                $sale->update($updateData);

                if (!empty($resRetry['already_registered'])) {
                    return [
                        'success'     => true,
                        'action'      => 'marked_accepted',
                        'message'     => "El comprobante {$sale->serie}-" . str_pad($sale->correlative, 8, '0', STR_PAD_LEFT) . " ya constaba en SUNAT (Código 1033). Se marcó como aceptado sin duplicarlo.",
                        'serie'       => $sale->serie,
                        'correlative' => $sale->correlative,
                    ];
                }

                return [
                    'success'     => true,
                    'action'      => 'retry_accepted',
                    'message'     => "El comprobante {$sale->serie}-" . str_pad($sale->correlative, 8, '0', STR_PAD_LEFT) . " fue enviado y aceptado por SUNAT con su número original.",
                    'serie'       => $sale->serie,
                    'correlative' => $sale->correlative,
                ];
            }

            // Caso B: Rechazo formal de SUNAT (1032 o códigos 2000 a 3999)
            if (!empty($resRetry['rejected_by_sunat'])) {
                $sale->update([
                    'sunat_status'     => 'rechazado',
                    'sunat_error'      => $resRetry['error'] ?? 'Rechazado por SUNAT',
                    'sunat_error_code' => $resRetry['code'] ?? null,
                ]);
                // Continuar inmediatamente al flujo de reemisión con número nuevo y fecha de hoy
            } else {
                // Caso C: Error de conexión o timeout en el reintento
                $updateConn = [
                    'sunat_error'      => 'Error de conexión con SUNAT al reintentar: ' . ($resRetry['error'] ?? ''),
                    'sunat_error_code' => $resRetry['code'] ?? null,
                ];
                if (!empty($removedZeroItems)) {
                    $history = is_array($sale->reemission_history) ? $sale->reemission_history : [];
                    $history[] = [
                        'serie'              => $sale->serie,
                        'correlative'        => $sale->correlative,
                        'issue_date'         => $sale->issue_date instanceof \DateTimeInterface ? $sale->issue_date->format('Y-m-d H:i:s') : (string)$sale->issue_date,
                        'sunat_status'       => 'pendiente_saneado',
                        'sunat_error'        => $resRetry['error'] ?? null,
                        'sunat_error_code'   => $resRetry['code'] ?? null,
                        'reemitted_at'       => now()->format('Y-m-d H:i:s'),
                        'reemitted_by'       => $user->id,
                        'reemitted_by_name'  => $user->name,
                        'removed_zero_items' => $removedZeroItems,
                    ];
                    $updateConn['reemission_history'] = $history;
                }
                $sale->update($updateConn);

                return [
                    'success' => false,
                    'action'  => 'retry_failed_connection',
                    'error'   => "No se pudo conectar con SUNAT para reintentar el comprobante {$sale->serie}-" . str_pad($sale->correlative, 8, '0', STR_PAD_LEFT) . " (" . ($resRetry['error'] ?? 'Error de conexión') . "). La venta sigue pendiente con su número actual.",
                ];
            }
        }

        // 5. Flujo de Reemisión con número nuevo y fecha de hoy
        return $this->ejecutarReemision($sale, $user, $sunatService, $removedZeroItems);
    }

    /**
     * Revisa si la venta tiene líneas con precio <= 0 o total <= 0:
     * - Si alguna línea en 0 es un producto con stock -> aborta sin tocar nada.
     * - Si es un servicio o REPE-GENERICO -> valida que al unir su texto a la línea anterior
     *   con precio > 0 no supere 255 caracteres. Si supera 255, aborta sin tocar nada.
     *   Si cabe, actualiza la línea anterior, borra la línea en 0 y devuelve los datos completos
     *   de la línea eliminada para guardarlos en reemission_history.
     */
    protected function procesarLineasPrecioCero(Sale $sale, User $user): array
    {
        $items = $sale->items()->with('productVariant.product')->orderBy('id')->get();
        if ($items->isEmpty()) {
            return ['can_proceed' => true, 'removed_zero_items' => []];
        }

        $zeroItems = $items->filter(fn ($i) => (float)$i->price <= 0 || (float)$i->total <= 0);
        if ($zeroItems->isEmpty()) {
            return ['can_proceed' => true, 'removed_zero_items' => []];
        }

        // 1. Verificar que ninguna línea en 0 sea de un producto con stock
        foreach ($zeroItems as $zItem) {
            $variant = $zItem->productVariant;
            $product = $variant?->product;
            $isServiceOrGeneric = ($product && $product->is_service) || ($variant && $variant->sku === 'REPE-GENERICO');

            if (!$isServiceOrGeneric) {
                return [
                    'can_proceed' => false,
                    'action'      => 'zero_price_stock_blocked',
                    'error'       => "La venta {$sale->serie}-" . str_pad($sale->correlative, 8, '0', STR_PAD_LEFT) . " tiene una línea en precio 0 de un producto con stock ('{$zItem->product_name}'). Se dejó sin reemitir para revisión manual.",
                ];
            }
        }

        // 2. Verificar que exista al menos una línea con precio > 0 donde anexar el texto
        $nonZeroItems = $items->filter(fn ($i) => (float)$i->price > 0 && (float)$i->total > 0)->values();
        if ($nonZeroItems->isEmpty()) {
            return [
                'can_proceed' => false,
                'action'      => 'all_lines_zero_blocked',
                'error'       => "La venta {$sale->serie}-" . str_pad($sale->correlative, 8, '0', STR_PAD_LEFT) . " solo tiene líneas con precio 0. Se dejó sin reemitir.",
            ];
        }

        // 3. Simular fusiones primero para verificar el límite de 255 caracteres sin tocar la BD si alguna excede
        $planMerges = [];
        $currentDescriptions = [];
        foreach ($nonZeroItems as $nz) {
            $currentDescriptions[$nz->id] = trim((string)$nz->product_name);
        }

        $lastValidItem = null;
        foreach ($items as $item) {
            $isZero = (float)$item->price <= 0 || (float)$item->total <= 0;
            if (!$isZero) {
                $lastValidItem = $item;
                continue;
            }

            $targetItem = $lastValidItem ?? $nonZeroItems->first();
            $zeroText = trim((string)$item->product_name);
            $baseDesc = $currentDescriptions[$targetItem->id];
            $newDesc = $zeroText !== '' ? ($baseDesc . ' - ' . $zeroText) : $baseDesc;

            if (mb_strlen($newDesc) > 255) {
                return [
                    'can_proceed' => false,
                    'action'      => 'merged_description_too_long',
                    'error'       => "La venta {$sale->serie}-" . str_pad($sale->correlative, 8, '0', STR_PAD_LEFT) . " no se reemitió porque la descripción unida supera los 255 caracteres (" . mb_strlen($newDesc) . " caracteres).",
                ];
            }

            $currentDescriptions[$targetItem->id] = $newDesc;
            $variant = $item->productVariant;
            $product = $variant?->product;

            $planMerges[] = [
                'zero_item_model' => $item,
                'target_item_id'  => $targetItem->id,
                'old_target_desc' => $baseDesc,
                'new_target_desc' => $newDesc,
                'history_record'  => [
                    'id'                 => $item->id,
                    'product_variant_id' => $item->product_variant_id,
                    'sku'                => $variant?->sku,
                    'product'            => $product?->name ?? 'Servicio / Genérico',
                    'product_name'       => $item->product_name,
                    'quantity'           => (int)$item->quantity,
                    'price'              => (float)$item->price,
                    'igv'                => (float)$item->igv,
                    'total'              => (float)$item->total,
                    'merged_into_item_id'=> $targetItem->id,
                    'resulting_text'     => $newDesc,
                ],
            ];
        }

        // 4. Aplicar los cambios en transacción atómica
        $removedHistory = [];
        DB::transaction(function () use ($nonZeroItems, $currentDescriptions, $planMerges, &$removedHistory) {
            foreach ($nonZeroItems as $nz) {
                $updatedDesc = $currentDescriptions[$nz->id];
                if ($updatedDesc !== $nz->product_name) {
                    $nz->update(['product_name' => $updatedDesc]);
                }
            }

            foreach ($planMerges as $merge) {
                $removedHistory[] = $merge['history_record'];
                $merge['zero_item_model']->delete();
            }
        });

        return [
            'can_proceed'        => true,
            'removed_zero_items' => $removedHistory,
        ];
    }

    /**
     * Ejecuta la reemisión estricta:
     * 1. Reserva y guarda número nuevo + fecha de hoy + historial en BD ANTES de enviar.
     * 2. Envía a SUNAT.
     * 3. Si falla la conexión, la venta queda "pendiente" con el nuevo número.
     */
    protected function ejecutarReemision(Sale $sale, User $user, SunatService $sunatService, array $removedZeroItems = []): array
    {
        $oldSerie       = $sale->serie;
        $oldCorrelative = $sale->correlative;
        $oldIssueDate   = $sale->issue_date;
        $oldError       = $sale->sunat_error;
        $oldErrorCode   = $sale->sunat_error_code;

        // 1. Obtener la serie activa de la MISMA sucursal (sin caer en series de otra tienda)
        $docType = str_starts_with(trim($sale->serie), 'F') ? '01' : '03';

        try {
            $serieInfo = DB::transaction(function () use ($sale, $docType) {
                $s = Serie::where('document_type', $docType)
                    ->where('branch_id', $sale->branch_id)
                    ->where('is_active', true)
                    ->orderByRaw('CASE WHEN serie = ? THEN 0 ELSE 1 END', [trim($sale->serie)])
                    ->lockForUpdate()
                    ->first();

                if (!$s) {
                    throw new \RuntimeException("No existe una serie activa configurada para el tipo de documento {$docType} en la sucursal de la venta (branch_id: {$sale->branch_id}).");
                }

                $nuevoCorrelativo = $s->current_number;
                $s->increment('current_number');

                return [
                    'serie'       => $s->serie,
                    'correlative' => $nuevoCorrelativo,
                ];
            });
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'action'  => 'serie_reservation_failed',
                'error'   => 'Error al reservar el nuevo correlativo: ' . $e->getMessage(),
            ];
        }

        $newSerie       = $serieInfo['serie'];
        $newCorrelative = $serieInfo['correlative'];
        $newIssueDate   = now();

        // 2. ORDEN AL REEMITIR: Primero guardar en la venta el nuevo número y el historial anterior
        $history = $sale->reemission_history ?? [];
        if (!is_array($history)) {
            $history = [];
        }

        $historyEntry = [
            'serie'             => $oldSerie,
            'correlative'       => $oldCorrelative,
            'issue_date'        => $oldIssueDate instanceof \DateTimeInterface ? $oldIssueDate->format('Y-m-d H:i:s') : (string)$oldIssueDate,
            'sunat_status'      => 'rechazado',
            'sunat_error'       => $oldError,
            'sunat_error_code'  => $oldErrorCode,
            'reemitted_at'      => $newIssueDate->format('Y-m-d H:i:s'),
            'reemitted_by'      => $user->id,
            'reemitted_by_name' => $user->name,
        ];

        if (!empty($removedZeroItems)) {
            $historyEntry['removed_zero_items'] = $removedZeroItems;
        }

        $history[] = $historyEntry;

        $updatePayload = [
            'serie'              => $newSerie,
            'correlative'        => $newCorrelative,
            'issue_date'         => $newIssueDate,
            'sunat_status'       => 'pendiente',
            'sunat_error'        => null,
            'sunat_error_code'   => null,
            'reemission_history' => $history,
        ];

        // Mantener el mismo plazo de crédito si la venta tiene due_date:
        // nuevo vencimiento = nueva fecha de emisión + días de crédito originales
        $newDueDateInMemory = null;
        if (!empty($sale->due_date) && $oldIssueDate) {
            $oldIssueCarbon = Carbon::parse($oldIssueDate)->startOfDay();
            $oldDueCarbon   = Carbon::parse($sale->due_date)->startOfDay();
            $creditDays     = max(0, (int) $oldIssueCarbon->diffInDays($oldDueCarbon, false));
            $newDueDate     = $newIssueDate->copy()->addDays($creditDays);

            if (Schema::hasColumn('sales', 'due_date')) {
                $updatePayload['due_date'] = $newDueDate;
            } else {
                $sale->offsetUnset('due_date');
                $newDueDateInMemory = $newDueDate;
            }
        }

        $sale->update($updatePayload);

        if ($newDueDateInMemory !== null) {
            $sale->setAttribute('due_date', $newDueDateInMemory);
        }

        // Recargar sucursal e ítems antes de construir el XML de Greenter
        $sale->unsetRelation('branch');
        $sale->unsetRelation('items');
        $sale->load(['branch', 'items']);

        // 3. Envío del nuevo comprobante a SUNAT
        try {
            $resSend = $sunatService->enviarBoleta($sale, isRetry: false);

            if ($newDueDateInMemory !== null) {
                $sale->offsetUnset('due_date');
            }

            if (isset($resSend['success']) && $resSend['success'] === true) {
                $sale->update([
                    'sunat_status'     => 'aceptado',
                    'sunat_error'      => null,
                    'sunat_error_code' => null,
                ]);
                if ($newDueDateInMemory !== null) {
                    $sale->setAttribute('due_date', $newDueDateInMemory);
                }

                return [
                    'success'         => true,
                    'action'          => 'reemitted_accepted',
                    'message'         => "Comprobante anterior ({$oldSerie}-" . str_pad($oldCorrelative, 8, '0', STR_PAD_LEFT) . ") reemitido exitosamente como {$newSerie}-" . str_pad($newCorrelative, 8, '0', STR_PAD_LEFT) . " y aceptado por SUNAT.",
                    'old_serie'       => $oldSerie,
                    'old_correlative' => $oldCorrelative,
                    'new_serie'       => $newSerie,
                    'new_correlative' => $newCorrelative,
                ];
            }

            if (!empty($resSend['rejected_by_sunat'])) {
                $sale->update([
                    'sunat_status'     => 'rechazado',
                    'sunat_error'      => $resSend['error'] ?? 'SUNAT rechazó el nuevo comprobante',
                    'sunat_error_code' => $resSend['code'] ?? null,
                ]);

                return [
                    'success'         => false,
                    'action'          => 'reemitted_rejected',
                    'error'           => "El nuevo comprobante {$newSerie}-" . str_pad($newCorrelative, 8, '0', STR_PAD_LEFT) . " fue rechazado por SUNAT: " . ($resSend['error'] ?? ''),
                    'old_serie'       => $oldSerie,
                    'old_correlative' => $oldCorrelative,
                    'new_serie'       => $newSerie,
                    'new_correlative' => $newCorrelative,
                ];
            }

            // Error de conexión o timeout durante el envío
            $sale->update([
                'sunat_status'     => 'pendiente',
                'sunat_error'      => 'Envío pendiente: ' . ($resSend['error'] ?? 'Error de conexión con SUNAT'),
                'sunat_error_code' => $resSend['code'] ?? null,
            ]);

            return [
                'success'         => false,
                'action'          => 'reemitted_pending',
                'error'           => "Se asignó el nuevo comprobante {$newSerie}-" . str_pad($newCorrelative, 8, '0', STR_PAD_LEFT) . ", pero falló la conexión con SUNAT (" . ($resSend['error'] ?? 'Timeout') . "). La venta quedó 'pendiente' con este nuevo número.",
                'old_serie'       => $oldSerie,
                'old_correlative' => $oldCorrelative,
                'new_serie'       => $newSerie,
                'new_correlative' => $newCorrelative,
            ];

        } catch (\Throwable $e) {
            Log::error("[SaleReemissionService] Error enviando comprobante reemitido ID {$sale->id}: " . $e->getMessage());

            if ($newDueDateInMemory !== null) {
                $sale->offsetUnset('due_date');
            }

            $sale->update([
                'sunat_status'     => 'pendiente',
                'sunat_error'      => 'Excepción al conectar con SUNAT: ' . $e->getMessage(),
                'sunat_error_code' => null,
            ]);

            return [
                'success'         => false,
                'action'          => 'reemitted_pending',
                'error'           => "Se asignó el nuevo comprobante {$newSerie}-" . str_pad($newCorrelative, 8, '0', STR_PAD_LEFT) . ", pero ocurrió un error inesperado al enviar a SUNAT. Quedó 'pendiente' para reintento.",
                'old_serie'       => $oldSerie,
                'old_correlative' => $oldCorrelative,
                'new_serie'       => $newSerie,
                'new_correlative' => $newCorrelative,
            ];
        }
    }

    /**
     * Procesa un lote de comprobantes (por lotes de 10 en memoria).
     */
    public function reemitirLote(iterable $sales, User $user): array
    {
        $report = [
            'total'              => 0,
            'marked_accepted'    => 0,
            'retry_accepted'     => 0,
            'reemitted_accepted' => 0,
            'reemitted_pending'  => 0,
            'reemitted_rejected' => 0,
            'failed'             => 0,
            'details'            => [],
        ];

        $items = collect($sales);
        $report['total'] = $items->count();

        foreach ($items->chunk(10) as $chunk) {
            foreach ($chunk as $sale) {
                /** @var Sale $sale */
                $res = $this->reemitirOReintentar($sale, $user);

                if (!empty($res['success'])) {
                    $action = $res['action'] ?? '';
                    if ($action === 'marked_accepted') {
                        $report['marked_accepted']++;
                    } elseif ($action === 'retry_accepted') {
                        $report['retry_accepted']++;
                    } elseif ($action === 'reemitted_accepted') {
                        $report['reemitted_accepted']++;
                    }
                    $report['details'][] = [
                        'sale_id' => $sale->id,
                        'status'  => 'success',
                        'message' => $res['message'] ?? 'OK',
                    ];
                } else {
                    $action = $res['action'] ?? '';
                    if ($action === 'reemitted_pending') {
                        $report['reemitted_pending']++;
                    } elseif ($action === 'reemitted_rejected') {
                        $report['reemitted_rejected']++;
                    } else {
                        $report['failed']++;
                    }
                    $report['details'][] = [
                        'sale_id' => $sale->id,
                        'status'  => 'error',
                        'message' => $res['error'] ?? 'Error desconocido',
                    ];
                }
            }
        }

        return $report;
    }
}
