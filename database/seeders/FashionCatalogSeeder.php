<?php

namespace Database\Seeders;

use App\Models\Color;
use App\Models\Size;
use App\Models\SizeCurve;
use Illuminate\Database\Seeder;

/**
 * Catálogos base de moda: tallas, curvas de tallas y colores frecuentes.
 * Se puede ejecutar varias veces sin duplicar.
 */
class FashionCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $order = 0;
        $sizes = [];
        foreach (['XS', 'S', 'M', 'L', 'XL', 'XXL', 'ÚNICA'] as $name) {
            $sizes[$name] = Size::firstOrCreate(['name' => $name], ['sort_order' => $order += 10]);
        }
        foreach ([26, 28, 30, 32, 34, 36, 38, 40, 42] as $n) {
            $sizes[(string) $n] = Size::firstOrCreate(['name' => (string) $n], ['sort_order' => 1000 + $n]);
        }
        foreach (range(35, 44) as $n) {
            // Las tallas 35–44 de calzado comparten número con pantalón solo en 36, 38, 40 y 42
            $sizes[(string) $n] ??= Size::firstOrCreate(['name' => (string) $n], ['sort_order' => 1000 + $n]);
        }

        $curves = [
            'Letras S–XL' => ['S', 'M', 'L', 'XL'],
            'Letras XS–XXL' => ['XS', 'S', 'M', 'L', 'XL', 'XXL'],
            'Talla única' => ['ÚNICA'],
            'Pantalón 28–40' => ['28', '30', '32', '34', '36', '38', '40'],
            'Calzado 35–44' => array_map('strval', range(35, 44)),
        ];
        foreach ($curves as $name => $sizeNames) {
            $curve = SizeCurve::firstOrCreate(['name' => $name]);
            if ($curve->sizes()->exists()) {
                continue;
            }
            foreach ($sizeNames as $i => $sizeName) {
                $curve->sizes()->attach($sizes[$sizeName]->id, ['sort_order' => $i]);
            }
        }

        $colors = [
            ['Negro', 'NEG', '#111827'], ['Blanco', 'BLA', '#FFFFFF'], ['Gris', 'GRI', '#9CA3AF'],
            ['Azul marino', 'AZM', '#1E3A8A'], ['Celeste', 'CEL', '#7DD3FC'], ['Rojo', 'ROJ', '#DC2626'],
            ['Beige', 'BEI', '#D6C4A1'], ['Verde oliva', 'OLI', '#556B2F'], ['Rosado', 'ROS', '#F9A8D4'],
            ['Marrón', 'MAR', '#7C4A2D'],
        ];
        foreach ($colors as $i => [$name, $code, $hex]) {
            Color::firstOrCreate(['code' => $code], ['name' => $name, 'hex' => $hex, 'sort_order' => ($i + 1) * 10]);
        }

        $this->command?->info('✅ Tallas, curvas y colores base cargados.');
    }
}
