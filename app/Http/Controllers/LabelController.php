<?php

namespace App\Http\Controllers;

use App\Models\CompanySetting;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Support\Ean13;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/**
 * Etiquetas de 50 × 30 mm con código de barras EAN-13 (Etapa 6).
 * Una etiqueta por prenda: para una compra, tantas como unidades recibidas.
 */
class LabelController extends Controller
{
    public function purchase(Purchase $purchase)
    {
        abort_unless(auth()->user()?->can('print_labels'), 403);
        abort_unless(auth()->user()->seesAllBranches() || (int) auth()->user()->branch_id === (int) $purchase->branch_id, 403);

        $labels = [];
        foreach ($purchase->items()->with('productVariant.product')->get() as $item) {
            for ($i = 0; $i < $item->quantity; $i++) {
                $labels[] = $item->productVariant;
            }
        }

        return $this->render($labels, "etiquetas-compra-{$purchase->id}.pdf");
    }

    /** ?items=ID:CANT,ID:CANT (desde la pantalla "Imprimir etiquetas"). */
    public function custom(Request $request)
    {
        abort_unless(auth()->user()?->can('print_labels'), 403);

        $wanted = collect(explode(',', (string) $request->query('items')))
            ->map(fn ($pair) => array_map('intval', explode(':', $pair) + [1 => 1]))
            ->filter(fn ($p) => $p[0] > 0 && $p[1] > 0 && $p[1] <= 500);

        $variants = ProductVariant::with('product')->whereIn('id', $wanted->pluck(0))->get()->keyBy('id');
        $labels = [];
        foreach ($wanted as [$id, $qty]) {
            if ($variant = $variants->get($id)) {
                $labels = array_merge($labels, array_fill(0, $qty, $variant));
            }
        }
        abort_if(empty($labels), 404, 'No hay prendas para imprimir.');

        return $this->render($labels, 'etiquetas.pdf');
    }

    private function render(array $variants, string $filename)
    {
        $labels = collect($variants)->map(function (ProductVariant $v) {
            $code = $v->barcode && Ean13::isValid($v->barcode) ? $v->barcode : Ean13::forVariant($v->id);

            return [
                'model' => $v->product?->name ?? '',
                'color' => $v->color_name && $v->color_name !== 'Único' ? $v->color_name : '',
                'size' => $v->size && $v->size !== 'Único' ? $v->size : '',
                'sku' => $v->sku,
                'price' => number_format($v->retail_price, 2),
                'code' => $code,
                'barcode' => Ean13::pngDataUri($code),
            ];
        });

        $pdf = Pdf::loadView('labels.pdf', [
            'labels' => $labels,
            'store' => CompanySetting::current()?->name,
        ])->setPaper([0, 0, 141.73, 85.04]); // 50 × 30 mm en puntos

        return $pdf->stream($filename);
    }
}
