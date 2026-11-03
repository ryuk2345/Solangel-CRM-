<?php

namespace App\Services;

use App\Models\CashRegister;
use App\Models\CashRegisterMovement;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cambios de talla o color y devoluciones (Etapa 8, decisión 7: nota de crédito + nueva boleta).
 *
 *  1. Se registran las prendas que el cliente devuelve: vuelven al almacén por el kardex.
 *  2. Si la venta fue boleta o factura, se emite una nota de crédito PARCIAL por esas prendas.
 *  3. Cambio: el valor devuelto queda como saldo a favor y se usa para pagar la venta nueva.
 *     Devolución: el administrador devuelve el dinero (sale de la caja como egreso).
 */
class SaleExchangeService
{
    /** Cantidad que todavía se puede devolver de cada línea de la venta: [sale_item_id => cantidad]. */
    public static function returnable(Sale $sale): array
    {
        $returned = SaleReturnItem::query()
            ->whereIn('sale_item_id', $sale->items()->pluck('id'))
            ->selectRaw('sale_item_id, SUM(quantity) as qty')
            ->groupBy('sale_item_id')
            ->pluck('qty', 'sale_item_id');

        return $sale->items()->with('productVariant.product')->get()
            ->reject(fn (SaleItem $i) => $i->productVariant?->product?->is_service)
            ->mapWithKeys(fn (SaleItem $i) => [$i->id => max(0, (int) $i->quantity - (int) ($returned[$i->id] ?? 0))])
            ->filter(fn ($q) => $q > 0)
            ->all();
    }

    public static function assertCanReturn(Sale $sale): void
    {
        if (in_array($sale->sunat_status, ['anulado', 'rechazado'], true)) {
            throw ValidationException::withMessages(['sale' => 'No se puede hacer cambios sobre una venta anulada o rechazada por SUNAT.']);
        }
        if (!str_starts_with((string) $sale->serie, 'NV') && $sale->sunat_status !== 'aceptado') {
            throw ValidationException::withMessages(['sale' => 'La boleta o factura todavía no está aceptada por SUNAT. Primero debe quedar aceptada.']);
        }
        if (filled($sale->credit_note_serie)) {
            throw ValidationException::withMessages(['sale' => 'Esta venta ya tiene una nota de crédito total (anulación).']);
        }
    }

    /**
     * @param  array<int,int>  $quantities  [sale_item_id => cantidad a devolver]
     * @param  string  $type  'cambio' | 'devolucion'
     */
    public static function register(Sale $sale, array $quantities, string $type, string $reason): SaleReturn
    {
        if (!in_array($type, ['cambio', 'devolucion'], true)) {
            throw ValidationException::withMessages(['type' => 'Tipo no válido.']);
        }
        if ($type === 'devolucion' && !Auth::user()?->can('void_sale')) {
            throw ValidationException::withMessages(['type' => 'Solo el administrador puede devolver dinero. Registra un cambio.']);
        }

        self::assertCanReturn($sale);

        $quantities = array_filter(array_map('intval', $quantities), fn ($q) => $q > 0);
        if (empty($quantities)) {
            throw ValidationException::withMessages(['items' => 'Indica qué prendas devuelve el cliente.']);
        }

        $return = DB::transaction(function () use ($sale, $quantities, $type, $reason) {
            Sale::whereKey($sale->id)->lockForUpdate()->first(); // evita dos cambios a la vez sobre la misma venta
            $available = self::returnable($sale);
            $items = SaleItem::whereIn('id', array_keys($quantities))->where('sale_id', $sale->id)->get()->keyBy('id');

            $total = 0.0;
            foreach ($quantities as $itemId => $qty) {
                $item = $items->get($itemId);
                if (!$item || !isset($available[$itemId])) {
                    throw ValidationException::withMessages(['items' => 'Una de las prendas no pertenece a la venta o ya fue devuelta.']);
                }
                if ($qty > $available[$itemId]) {
                    throw ValidationException::withMessages(['items' => "{$item->product_name}: se pueden devolver como máximo {$available[$itemId]}."]);
                }
                // Se devuelve al precio que pagó (precio guardado en la venta, nunca el actual)
                $total += round(((float) $item->total / max(1, (int) $item->quantity)) * $qty, 2);
            }

            $warehouseId = (int) ($sale->warehouse_id ?: \App\Support\SaleCart::warehouseFor($sale->branch_id, null));

            $return = SaleReturn::create([
                'sale_id' => $sale->id,
                'branch_id' => $sale->branch_id,
                'warehouse_id' => $warehouseId,
                'user_id' => Auth::id(),
                'type' => $type,
                'reason' => $reason,
                'total' => round($total, 2),
                'sunat_status' => str_starts_with((string) $sale->serie, 'NV') ? 'interna' : 'pendiente',
            ]);

            foreach ($quantities as $itemId => $qty) {
                $item = $items->get($itemId);
                $unit = (float) $item->total / max(1, (int) $item->quantity);
                $return->items()->create([
                    'sale_item_id' => $item->id,
                    'product_variant_id' => $item->product_variant_id,
                    'product_name' => $item->product_name,
                    'quantity' => $qty,
                    'price' => round($unit, 2),
                    'total' => round($unit * $qty, 2),
                ]);

                StockService::move((int) $item->product_variant_id, $warehouseId, $qty, 'exchange_in', $return,
                    ($type === 'cambio' ? 'Cambio' : 'Devolución') . " de {$sale->serie}-{$sale->correlative}");
            }

            return $return;
        });

        if ($return->sunat_status === 'pendiente') {
            self::sendCreditNote($return);
        }

        return $return->fresh(['items', 'sale']);
    }

    /** Emite (o reintenta) la nota de crédito parcial en SUNAT. */
    public static function sendCreditNote(SaleReturn $return): SaleReturn
    {
        if ($return->sunat_status === 'interna' || $return->sunat_status === 'aceptado') {
            return $return;
        }

        $result = app(SunatService::class)->emitirNotaCreditoParcial($return->fresh(['items', 'sale']));

        if (!empty($result['success'])) {
            $return->update(['sunat_status' => 'aceptado', 'sunat_error' => null]);
        } else {
            $rejected = !empty($result['rejected_by_sunat']);
            $return->update([
                'sunat_status' => $rejected ? 'rechazado' : 'pendiente',
                'sunat_error' => $result['error'] ?? 'Error desconocido',
                // Si SUNAT la rechazó, el próximo intento usa un número nuevo
                'credit_note_serie' => $rejected ? null : $return->credit_note_serie,
                'credit_note_correlative' => $rejected ? null : $return->credit_note_correlative,
            ]);
        }

        return $return->fresh();
    }

    /** Saldo a favor de una devolución tipo cambio que aún no se usó. */
    public static function availableCredit(?int $returnId): float
    {
        return $returnId ? (float) (SaleReturn::find($returnId)?->available_credit ?? 0) : 0.0;
    }

    /** Une la venta nueva con su cambio (una sola vez). */
    public static function linkNewSale(int $returnId, Sale $newSale): void
    {
        $updated = SaleReturn::whereKey($returnId)->where('type', 'cambio')->whereNull('new_sale_id')
            ->update(['new_sale_id' => $newSale->id]);

        if (!$updated) {
            throw ValidationException::withMessages(['exchange' => 'El saldo a favor de este cambio ya se usó.']);
        }
    }

    /** Devolución de dinero: egreso de la caja abierta de quien devuelve. */
    public static function refundCash(SaleReturn $return, float $amount): void
    {
        if ($return->type !== 'devolucion') {
            return;
        }
        $amount = round(min($amount, (float) $return->total), 2);

        // Venta a crédito: la devolución primero reduce lo que el cliente debe; solo el resto se devuelve en efectivo
        $sale = Sale::whereKey($return->sale_id)->lockForUpdate()->first();
        if ($sale && (float) $sale->credit_balance > 0) {
            $applied = round(min((float) $sale->credit_balance, $amount), 2);
            $sale->update(['credit_balance' => round((float) $sale->credit_balance - $applied, 2)]);
            $amount = round($amount - $applied, 2);
        }

        if ($amount <= 0) {
            return;
        }

        $register = CashRegister::where('user_id', Auth::id())->where('branch_id', $return->branch_id)
            ->where('status', 'abierta')->latest('opened_at')->first()
            ?? CashRegister::getActiveForSuperAdmin($return->branch_id);

        if (!$register) {
            throw ValidationException::withMessages(['cash' => 'No hay una caja abierta para devolver el dinero.']);
        }

        DB::transaction(function () use ($register, $return, $amount) {
            CashRegisterMovement::create([
                'cash_register_id' => $register->id,
                'user_id' => Auth::id(),
                'type' => 'egreso',
                'amount' => $amount,
                'description' => "Devolución de dinero {$return->sale?->serie}-{$return->sale?->correlative}"
                    . ($return->credit_note_number ? " (NC {$return->credit_note_number})" : ''),
            ]);
            $register->increment('cash_expenses', $amount);
            $return->update(['refunded_cash' => $amount]);
        });
    }
}
