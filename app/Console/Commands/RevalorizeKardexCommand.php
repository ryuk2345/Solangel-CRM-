<?php

namespace App\Console\Commands;

use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RevalorizeKardexCommand extends Command
{
    protected $signature = 'kardex:revalorizar {--warehouse= : Filtrar por almacén ID} {--variant= : Filtrar por variante ID}';

    protected $description = 'Recalcula costos y documentos de los movimientos de kardex en orden cronológico (idempotente).';

    public function handle(): int
    {
        $this->info('Iniciando revalorización cronológica del kardex...');

        $query = StockMovement::query()
            ->select('warehouse_id', 'product_variant_id')
            ->distinct();

        if ($wh = $this->option('warehouse')) {
            $query->where('warehouse_id', $wh);
        }
        if ($vr = $this->option('variant')) {
            $query->where('product_variant_id', $vr);
        }

        $pairs = $query->get();
        $totalPairs = $pairs->count();
        $totalMovements = 0;

        $this->line("Procesando {$totalPairs} combinaciones de almacén × variante...");

        DB::transaction(function () use ($pairs, &$totalMovements) {
            foreach ($pairs as $pair) {
                $variant = ProductVariant::with('product')->find($pair->product_variant_id);
                $defaultCost = (float) ($variant?->purchase_price ?: ($variant?->product?->purchase_price ?: 0.0));

                $movements = StockMovement::where('warehouse_id', $pair->warehouse_id)
                    ->where('product_variant_id', $pair->product_variant_id)
                    ->orderBy('created_at', 'asc')
                    ->orderBy('id', 'asc')
                    ->get();

                $balanceQty = 0;
                $balanceTotalCost = 0.0;
                $balanceUnitCost = 0.0;

                foreach ($movements as $m) {
                    $totalMovements++;
                    $paymentDoc = $m->payment_document;
                    $guideDoc = $m->guide_document;

                    // Resolver documentos faltantes a partir de la relación polimórfica
                    if (!$paymentDoc && $m->reference_type && $m->reference_id) {
                        if ($m->reference_type === (new Sale)->getMorphClass() || $m->reference_type === Sale::class) {
                            $sale = Sale::find($m->reference_id);
                            if ($sale) {
                                $paymentDoc = "{$sale->serie}-{$sale->correlative}";
                            }
                        } elseif ($m->reference_type === (new Purchase)->getMorphClass() || $m->reference_type === Purchase::class) {
                            $purchase = Purchase::find($m->reference_id);
                            if ($purchase) {
                                $paymentDoc = $purchase->document_number;
                            }
                        } elseif ($m->reference_type === (new SaleReturn)->getMorphClass() || $m->reference_type === SaleReturn::class) {
                            $ret = SaleReturn::with('sale')->find($m->reference_id);
                            if ($ret?->sale) {
                                $paymentDoc = "{$ret->sale->serie}-{$ret->sale->correlative}";
                            }
                        }
                    }

                    if (!$guideDoc && $m->reference_type && $m->reference_id) {
                        if ($m->reference_type === (new StockTransfer)->getMorphClass() || $m->reference_type === StockTransfer::class) {
                            $transfer = StockTransfer::with('despatch')->find($m->reference_id);
                            if ($transfer) {
                                $guideDoc = $transfer->despatch?->document_number ?? $transfer->number;
                            }
                        }
                    }

                    // Determinar costo unitario
                    $unitCost = 0.0;
                    if ($m->type === 'purchase') {
                        if ((float) $m->unit_cost > 0) {
                            $unitCost = (float) $m->unit_cost;
                        } elseif ($m->reference_type && $m->reference_id) {
                            $purchase = Purchase::find($m->reference_id);
                            $pItem = $purchase?->items()->where('product_variant_id', $pair->product_variant_id)->first();
                            $unitCost = $pItem ? (float) $pItem->unit_cost : ($defaultCost ?: $balanceUnitCost);
                        } else {
                            $unitCost = $defaultCost ?: $balanceUnitCost;
                        }
                    } elseif ($m->type === 'purchase_void') {
                        $unitCost = (float) $m->unit_cost > 0 ? (float) $m->unit_cost : ($balanceUnitCost ?: $defaultCost);
                    } elseif (in_array($m->type, ['initial', 'transfer_in', 'transfer_return']) || ($m->type === 'adjustment' && $m->quantity > 0)) {
                        $unitCost = (float) $m->unit_cost > 0 ? (float) $m->unit_cost : ($balanceUnitCost ?: $defaultCost);
                    } elseif (in_array($m->type, ['sale_void', 'exchange_in'])) {
                        $unitCost = (float) $m->unit_cost > 0 ? (float) $m->unit_cost : ($balanceUnitCost ?: $defaultCost);
                    } else {
                        // Salidas: costo promedio vigente
                        $unitCost = $balanceUnitCost > 0 ? $balanceUnitCost : ((float) $m->unit_cost > 0 ? (float) $m->unit_cost : $defaultCost);
                    }

                    // Cálculo de totales y saldos
                    if ($m->type === 'purchase' || $m->type === 'purchase_void' || in_array($m->type, ['initial', 'transfer_in', 'transfer_return']) || ($m->type === 'adjustment' && $m->quantity > 0)) {
                        $totalCost = round($m->quantity * $unitCost, 2);
                        $balanceQty += (int) $m->quantity;
                        $balanceTotalCost = round($balanceTotalCost + $totalCost, 2);
                        $balanceUnitCost = $balanceQty > 0 ? round($balanceTotalCost / $balanceQty, 4) : 0.0;
                    } elseif (in_array($m->type, ['sale_void', 'exchange_in'])) {
                        $totalCost = round($m->quantity * $unitCost, 2);
                        $balanceQty += (int) $m->quantity;
                        $balanceTotalCost = round($balanceTotalCost + $totalCost, 2);
                        $balanceUnitCost = $balanceQty > 0 ? round($balanceTotalCost / $balanceQty, 4) : 0.0;
                    } else {
                        $totalCost = round($m->quantity * $unitCost, 2);
                        $balanceQty += (int) $m->quantity;
                        $balanceTotalCost = round($balanceTotalCost + $totalCost, 2);
                        $balanceUnitCost = $balanceQty > 0 ? $unitCost : 0.0;
                    }

                    if ($balanceQty <= 0) {
                        $balanceTotalCost = 0.0;
                        $balanceUnitCost = 0.0;
                    }

                    $m->update([
                        'unit_cost' => $unitCost,
                        'total_cost' => $totalCost,
                        'balance_after' => $balanceQty,
                        'balance_unit_cost' => $balanceUnitCost,
                        'balance_total_cost' => $balanceTotalCost,
                        'guide_document' => $guideDoc,
                        'payment_document' => $paymentDoc,
                    ]);
                }
            }
        });

        $this->info("Kardex revalorizado con éxito. Se actualizaron {$totalMovements} movimientos.");

        return self::SUCCESS;
    }
}
