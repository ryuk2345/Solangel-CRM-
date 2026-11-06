<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WholesalePriceRequest;

/**
 * Regla de precios minorista / mayorista (PLAN_MODA §5 y decisiones del cliente):
 *
 *  - Desde N prendas en la venta (cualquier combinación de modelos; N configurable, 2 por defecto),
 *    todas las prendas van a precio mayorista.
 *  - Con menos prendas, precio minorista, salvo que un administrador haya aprobado con su PIN
 *    una solicitud para ese cliente mayorista.
 *  - Solo quien tiene el permiso edit_sale_price (administrador) puede fijar un precio a mano.
 *  - El delivery no cuenta como prenda y conserva el precio indicado.
 *
 * Se usa en el formulario (para mostrar) y en el servidor al guardar (lo que manda el formulario no se cree).
 */
class PricingService
{
    /**
     * @param  array<int|string, array>  $lines  Cada línea: product_variant_id, quantity, y opcionalmente
     *                                           price (precio escrito en el formulario) y price_reason.
     * @param  string|null  $exchangeMode  En un cambio de talla, el modo de la venta original: si fue mayorista,
     *                                     la prenda de reemplazo conserva el precio mayorista.
     * @return array{lines: array, mode: string, garments: int, min: int, reason: ?string, total: float}
     */
    public static function apply(array $lines, ?int $customerId, ?int $branchId, ?int $wholesaleRequestId = null, ?User $actor = null, ?string $exchangeMode = null): array
    {
        $min = CompanySetting::wholesaleMinQty();
        $variants = ProductVariant::with('product')
            ->whereIn('id', collect($lines)->pluck('product_variant_id')->filter()->unique())
            ->get()->keyBy('id');

        $garments = collect($lines)->sum(function ($line) use ($variants) {
            $variant = $variants->get((int) ($line['product_variant_id'] ?? 0));

            return $variant && !$variant->product?->is_service ? max(0, (int) ($line['quantity'] ?? 0)) : 0;
        });

        $mode = 'minorista';
        $reason = null;
        if ($garments >= $min) {
            $mode = 'mayorista';
            $reason = 'quantity';
        } elseif ($garments > 0 && $wholesaleRequestId) {
            $request = WholesalePriceRequest::find($wholesaleRequestId);
            if ($request && $request->isUsableFor($customerId, $branchId)) {
                $mode = 'mayorista';
                $reason = 'authorized';
            }
        }
        if ($mode === 'minorista' && $garments > 0 && $exchangeMode === 'mayorista') {
            $mode = 'mayorista';
            $reason = 'exchange';
        }

        $canEditPrice = (bool) $actor?->can('edit_sale_price');
        $total = 0.0;

        foreach ($lines as $key => $line) {
            $variant = $variants->get((int) ($line['product_variant_id'] ?? 0));
            if (!$variant) {
                continue;
            }

            $qty = max(1, (int) ($line['quantity'] ?? 1));

            if ($variant->product?->is_service) {
                // Delivery: precio indicado en la línea (o el sugerido)
                $price = round((float) ($line['price'] ?? 0) ?: (float) ($variant->product->sale_price ?? 0), 2);
                $lines[$key] = array_merge($line, [
                    'price' => $price,
                    'list_price' => $price,
                    'price_type' => 'service',
                    'price_reason' => null,
                ]);
            } else {
                $listPrice = $mode === 'mayorista' ? $variant->effective_wholesale_price : $variant->retail_price;
                $price = $listPrice;
                $lineReason = $reason;

                // Precio a mano: solo el administrador, y solo si lo marcó como manual
                $typed = isset($line['price']) && is_numeric($line['price']) ? round((float) $line['price'], 2) : null;
                if ($canEditPrice && ($line['price_reason'] ?? null) === 'manual' && $typed !== null && $typed > 0) {
                    $price = $typed;
                    $lineReason = 'manual';
                }

                $lines[$key] = array_merge($line, [
                    'price' => round($price, 2),
                    'list_price' => round($listPrice, 2),
                    'price_type' => $mode === 'mayorista' ? 'wholesale' : 'retail',
                    'price_reason' => $lineReason,
                ]);
            }

            $lineTotal = round($lines[$key]['price'] * $qty, 2);
            $lines[$key]['quantity'] = $qty;
            $lines[$key]['total'] = number_format($lineTotal, 2, '.', '');
            $lines[$key]['igv'] = round($lineTotal - ($lineTotal / 1.18), 2);
            $total += $lineTotal;
        }

        return [
            'lines' => $lines,
            'mode' => $mode,
            'garments' => $garments,
            'min' => $min,
            'reason' => $reason,
            'total' => round($total, 2),
        ];
    }

    /** Texto para el indicador del punto de venta. */
    public static function summary(array $result): string
    {
        if ($result['garments'] === 0) {
            return 'Agrega prendas para calcular el precio.';
        }
        if ($result['mode'] === 'mayorista') {
            return match ($result['reason']) {
                'authorized' => "PRECIO MAYORISTA · autorizado por el administrador ({$result['garments']} prenda)",
                'exchange' => 'PRECIO MAYORISTA · cambio de una venta mayorista',
                default => "PRECIO MAYORISTA · {$result['garments']} prendas",
            };
        }
        $missing = $result['min'] - $result['garments'];

        return "PRECIO MINORISTA · {$result['garments']} prenda" . ($result['garments'] === 1 ? '' : 's')
            . " (faltan {$missing} para precio mayorista)";
    }
}
