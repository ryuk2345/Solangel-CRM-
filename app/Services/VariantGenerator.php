<?php

namespace App\Services;

use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;
use Illuminate\Support\Facades\DB;

/**
 * Genera las variantes de un modelo: una por cada color × talla elegidos.
 * SKU: CODIGO-COLOR-TALLA (ej. M00012-NEG-M). Las combinaciones que ya existen no se tocan.
 */
class VariantGenerator
{
    /**
     * @param  array<int>  $colorIds
     * @param  array<int>  $sizeIds
     * @return int Cantidad de variantes nuevas
     */
    public static function generate(Product $product, array $colorIds, array $sizeIds): int
    {
        $colors = Color::whereIn('id', $colorIds)->ordered()->get();
        $sizes = Size::whereIn('id', $sizeIds)->ordered()->get();

        if ($colors->isEmpty() || $sizes->isEmpty()) {
            return 0;
        }

        $product->refresh();
        $code = $product->code ?: 'M' . str_pad((string) $product->id, 5, '0', STR_PAD_LEFT);
        $created = 0;

        DB::transaction(function () use ($product, $colors, $sizes, $code, &$created) {
            foreach ($colors as $color) {
                foreach ($sizes as $size) {
                    $exists = ProductVariant::where('product_id', $product->id)
                        ->where('color_id', $color->id)
                        ->where('size_id', $size->id)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    $sku = self::uniqueSku("{$code}-{$color->code}-{$size->name}");

                    ProductVariant::create([
                        'product_id' => $product->id,
                        'color_id' => $color->id,
                        'size_id' => $size->id,
                        'sku' => $sku,
                        'is_active' => true,
                    ]);
                    $created++;
                }
            }
        });

        return $created;
    }

    private static function uniqueSku(string $base): string
    {
        $base = strtoupper(preg_replace('/\s+/', '', $base));
        $sku = $base;
        $i = 2;
        while (ProductVariant::where('sku', $sku)->exists()) {
            $sku = "{$base}-{$i}";
            $i++;
        }

        return $sku;
    }
}
