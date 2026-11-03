<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\User;
use App\Models\Inventory;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SaleAnulacionService
{
    /**
     * Anula una venta de forma segura y unificada:
     * - Notas de Venta: devolución de stock interna sin invocar SUNAT.
     * - Boletas/Facturas no aceptadas (pendiente o error): primero consulta/reenvía a SUNAT.
     *   * Si SUNAT la acepta: emite Nota de Crédito.
     *   * Si SUNAT la rechaza formalmente (2000-3999): anula internamente y devuelve stock sin NC.
     *   * Si hay error de conexión/timeout: aborta sin anular por seguridad tributaria.
     * - Boletas/Facturas aceptadas: emite Nota de Crédito ante SUNAT. Si y solo si SUNAT acepta (CDR isAccepted),
     *   devuelve stock y marca la venta como anulada en una sola transacción.
     */
    public static function anularVenta(Sale $sale, array $data, User $user): array
    {
        // 1. Control estricto de sucursal para cajeros (vendedor)
        if (!$user->seesAllBranches() && (int)$user->branch_id !== (int)$sale->branch_id) {
            return [
                'error' => 'No tiene permiso para anular ventas de otra sucursal.'
            ];
        }

        // 1b. Si ya hubo cambios o devoluciones parciales, la nota de crédito total duplicaría el crédito
        if ($sale->returns()->exists()) {
            return [
                'error' => 'Esta venta tiene cambios o devoluciones registradas. Para devolver el resto de prendas usa "Cambio / devolución".',
            ];
        }

        // 2. Control contra anulación duplicada
        if ($sale->sunat_status === 'anulado') {
            return [
                'error' => 'Esta venta ya se encuentra anulada.'
            ];
        }

        $esNotaInterna = str_starts_with($sale->serie, 'NV');

        // Caso 1: NOTA DE VENTA (Registro interno)
        if ($esNotaInterna) {
            DB::transaction(function () use ($sale) {
                self::devolverStockVenta($sale);
                $sale->update(['sunat_status' => 'anulado']);
            });

            return [
                'success' => true,
                'type' => 'nv',
                'title' => 'Venta Interna Anulada',
                'message' => 'La venta interna fue anulada correctamente y el stock ha sido devuelto al inventario.',
            ];
        }

        // Caso 2: BOLETA O FACTURA
        $sunatService = new SunatService();

        // Si la boleta o factura aún NO está aceptada (pendiente, pending o con fallo de envío previo)
        if ($sale->sunat_status !== 'aceptado') {
            $resEnvio = $sunatService->enviarBoleta($sale, isRetry: true);

            if (isset($resEnvio['success']) && $resEnvio['success'] === true) {
                // Ahora consta como aceptada en SUNAT
                $sale->update(['sunat_status' => 'aceptado']);
            } elseif (!empty($resEnvio['rejected_by_sunat'])) {
                // SUNAT rechazó formalmente el documento (código 2000-3999).
                // No tiene validez tributaria ante SUNAT: se anula internamente sin Nota de Crédito.
                DB::transaction(function () use ($sale) {
                    self::devolverStockVenta($sale);
                    $sale->update(['sunat_status' => 'anulado']);
                });

                return [
                    'success' => true,
                    'type' => 'rechazo_sunat',
                    'title' => 'Comprobante Rechazado por SUNAT',
                    'message' => 'SUNAT rechazó formalmente este comprobante. Fue anulado internamente y el stock ha sido devuelto sin requerir Nota de Crédito.',
                ];
            } else {
                // Error de conexión, timeout o servidor SUNAT inaccesible
                return [
                    'error' => 'No se pudo verificar el estado en SUNAT (' . ($resEnvio['error'] ?? 'Error de conexión') . '). Por seguridad tributaria, no se puede anular hasta confirmar con SUNAT.'
                ];
            }
        }

        // En este punto, la boleta o factura está estrictamente aceptada ante SUNAT.
        // Debe emitirse obligatoriamente la Nota de Crédito.
        $codigoMotivo = $data['codigo_motivo'] ?? '01';
        $motivoTexto = !empty($data['motivo_texto']) ? trim($data['motivo_texto']) : 'Anulación de la operación';

        $resNC = $sunatService->emitirNotaCredito($sale, $codigoMotivo, $motivoTexto);

        if (!isset($resNC['success']) || $resNC['success'] !== true) {
            return [
                'error' => $resNC['error'] ?? 'SUNAT rechazó o no procesó la Nota de Crédito. La venta no fue anulada.'
            ];
        }

        // Validar CDR aceptado (a menos que sea reintento con código 1033 donde SUNAT no retorna nuevo CDR)
        $ruc = \App\Models\CompanySetting::first()?->ruc ?? env('EMPRESA_RUC', '20607603007');
        $cdrPath = storage_path("app/sunat/cdrs/R-{$ruc}-07-{$sale->credit_note_serie}-" . str_pad($sale->credit_note_correlative, 8, '0', STR_PAD_LEFT) . ".zip");
        if (empty($resNC['already_registered']) && !SunatNoteReader::isCdrAccepted($cdrPath)) {
            return [
                'error' => 'SUNAT emitió una constancia (CDR) de rechazo para la Nota de Crédito. La venta no fue anulada.'
            ];
        }

        // SUNAT aceptó la Nota de Crédito: devolver stock y marcar anulado en una sola transacción
        DB::transaction(function () use ($sale) {
            self::devolverStockVenta($sale);
            $sale->update(['sunat_status' => 'anulado']);
        });

        $numeroNC = "{$sale->credit_note_serie}-" . str_pad($sale->credit_note_correlative, 8, '0', STR_PAD_LEFT);

        return [
            'success' => true,
            'type' => 'credit_note',
            'title' => 'Nota de Crédito Emitida con Éxito',
            'message' => "La Nota de Crédito {$numeroNC} fue aceptada por SUNAT. El stock ha sido devuelto.",
            'sale' => $sale,
            'credit_note_number' => $numeroNC,
        ];
    }

    /**
     * Regulariza la emisión de Nota de Crédito ante SUNAT para comprobantes
     * que ya figuran anulados en el sistema pero quedaron "Sin nota de crédito".
     * NO devuelve inventario por segunda vez (ya fue devuelto al anular originalmente).
     */
    public static function regularizarNotaCredito(Sale $sale, array $data, User $user): array
    {
        // 1. Control estricto de sucursal para cajeros (vendedor)
        if (!$user->seesAllBranches() && (int)$user->branch_id !== (int)$sale->branch_id) {
            return [
                'error' => 'No tiene permiso para regularizar ventas de otra sucursal.'
            ];
        }

        // 2. Solo boletas o facturas
        if (str_starts_with($sale->serie, 'NV')) {
            return [
                'error' => 'Las notas de venta son internas y no admiten Nota de Crédito ante SUNAT.'
            ];
        }

        // 3. Debe figurar como anulada en el sistema
        if ($sale->sunat_status !== 'anulado') {
            return [
                'error' => 'Esta venta no figura anulada. Use la opción de anulación estándar.'
            ];
        }

        // 4. Verificar que NO tenga ya una Nota de Crédito emitida y válida
        $ncExistente = SunatNoteReader::buscarNotaParaVenta($sale, 'credito');
        if ($ncExistente) {
            return [
                'error' => "Esta venta ya cuenta con la Nota de Crédito {$ncExistente['full_number']} en SUNAT."
            ];
        }

        $sunatService = new SunatService();

        // 5. Verificar que el comprobante original figure registrado o aceptado
        $ruc = \App\Models\CompanySetting::first()?->ruc ?? env('EMPRESA_RUC', '20607603007');
        $tipoDocOrig = str_starts_with($sale->serie, 'F') ? '01' : '03';
        $corrPadOrig = str_pad($sale->correlative, 8, '0', STR_PAD_LEFT);
        $cdrOrigPath = storage_path("app/sunat/cdrs/R-{$ruc}-{$tipoDocOrig}-{$sale->serie}-{$corrPadOrig}.zip");
        $xmlOrigPath = storage_path("app/sunat/xmls/{$ruc}-{$tipoDocOrig}-{$sale->serie}-{$corrPadOrig}.xml");

        $constatadoEnDisco = (file_exists($cdrOrigPath) && SunatNoteReader::isCdrAccepted($cdrOrigPath)) || file_exists($xmlOrigPath);

        if (!$constatadoEnDisco) {
            $resConsulta = $sunatService->enviarBoleta($sale, isRetry: true);
            if (!empty($resConsulta['rejected_by_sunat'])) {
                return [
                    'error' => 'SUNAT rechazó formalmente el comprobante original (' . ($resConsulta['error'] ?? '') . '). No tiene validez fiscal y no requiere Nota de Crédito.'
                ];
            }

            if (!isset($resConsulta['success']) && empty($resConsulta['already_registered'])) {
                return [
                    'error' => 'No se pudo conectar con SUNAT para validar el comprobante original: ' . ($resConsulta['error'] ?? 'Error de conexión.')
                ];
            }
        }

        // 6. Emitir la Nota de Crédito ante SUNAT
        $codigoMotivo = $data['codigo_motivo'] ?? '01';
        $motivoTexto = !empty($data['motivo_texto']) ? trim($data['motivo_texto']) : 'Regularización de anulación previa';

        $resNC = $sunatService->emitirNotaCredito($sale, $codigoMotivo, $motivoTexto);

        if (!isset($resNC['success']) || $resNC['success'] !== true) {
            return [
                'error' => $resNC['error'] ?? 'SUNAT no pudo procesar la Nota de Crédito.'
            ];
        }

        // 7. Validar CDR aceptado
        $ruc = \App\Models\CompanySetting::first()?->ruc ?? env('EMPRESA_RUC', '20607603007');
        $cdrPath = storage_path("app/sunat/cdrs/R-{$ruc}-07-{$sale->credit_note_serie}-" . str_pad($sale->credit_note_correlative, 8, '0', STR_PAD_LEFT) . ".zip");
        if (empty($resNC['already_registered']) && !SunatNoteReader::isCdrAccepted($cdrPath)) {
            return [
                'error' => 'SUNAT emitió una constancia (CDR) de rechazo para la Nota de Crédito.'
            ];
        }

        // 8. Éxito: NOTA: NO se incrementa inventario porque ya fue devuelto en la anulación original
        $numeroNC = "{$sale->credit_note_serie}-" . str_pad($sale->credit_note_correlative, 8, '0', STR_PAD_LEFT);

        return [
            'success' => true,
            'type' => 'credit_note',
            'title' => 'Nota de Crédito Regularizada con Éxito',
            'message' => "La Nota de Crédito {$numeroNC} fue aceptada por SUNAT. El inventario se mantuvo intacto ya que fue devuelto en la anulación previa.",
            'sale' => $sale,
            'credit_note_number' => $numeroNC,
        ];
    }

    /**
     * Devuelve el stock de todos los ítems de la venta al almacén correspondiente.
     */
    public static function devolverStockVenta(Sale $sale): void
    {
        $warehouseId = $sale->warehouse_id ?? $sale->branch?->defaultWarehouse?->id ?? 1;

        foreach ($sale->items as $item) {
            $variant = $item->productVariant ?? ProductVariant::find($item->product_variant_id);
            if ($variant?->product?->is_service) {
                continue;
            }

            // Devuelve el stock por el kardex (movimiento 'sale_void' enlazado a la venta)
            StockService::move(
                (int) $item->product_variant_id,
                (int) $warehouseId,
                (int) $item->quantity,
                'sale_void',
                $sale,
                "Anulación {$sale->serie}-{$sale->correlative}",
            );
        }
    }
}
