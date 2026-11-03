<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Color;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Season;
use App\Models\Size;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
use App\Services\VariantGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Datos de DEMOSTRACIÓN para probar en local (no se ejecuta con db:seed normal):
 *   php artisan db:seed --class=DemoDataSeeder
 *
 * Crea modelos con colores y tallas, stock inicial (por el kardex), clientes minorista y mayorista,
 * el delivery y un usuario por rol. La contraseña de los usuarios demo sale de DEMO_PASSWORD
 * o se genera y se muestra una sola vez en la terminal.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->error('DemoDataSeeder no se ejecuta en producción.');

            return;
        }

        $branch = Branch::orderBy('id')->firstOrFail();
        $warehouse = Warehouse::where('branch_id', $branch->id)->where('is_default', true)->firstOrFail();
        $admin = User::role('super_admin')->first() ?? User::first();
        Auth::login($admin); // para que el kardex registre quién cargó el stock inicial

        $season = Season::firstOrCreate(['name' => 'Verano 2027'], ['is_active' => true]);
        $brand = Brand::firstOrCreate(['name' => 'Solangel']);
        $cats = collect(['Polos', 'Jeans', 'Casacas', 'Blusas'])->mapWithKeys(fn ($n) => [$n => Category::firstOrCreate(['slug' => Str::slug($n)], ['name' => $n])]);

        $models = [
            // nombre, categoría, minorista, mayorista, costo, colores, tallas, delivery, stock por variante
            ['Polo básico algodón pima', 'Polos', 59.90, 45.00, 22.00, ['NEG', 'BLA', 'AZM', 'BEI'], ['S', 'M', 'L', 'XL'], true, 25],
            ['Jean slim fit', 'Jeans', 139.90, 110.00, 58.00, ['AZM', 'NEG'], ['28', '30', '32', '34', '36'], true, 12],
            ['Casaca rompevientos', 'Casacas', 149.90, 120.00, 68.00, ['NEG', 'OLI', 'BEI'], ['S', 'M', 'L', 'XL'], false, 8],
            ['Blusa lino manga corta', 'Blusas', 79.90, 62.00, 30.00, ['BLA', 'ROS'], ['XS', 'S', 'M', 'L'], true, 30],
        ];

        foreach ($models as [$name, $cat, $retail, $wholesale, $cost, $colorCodes, $sizeNames, $delivery, $stock]) {
            $product = Product::firstOrCreate(['name' => $name], [
                'category_id' => $cats[$cat]->id,
                'brand_id' => $brand->id,
                'season_id' => $season->id,
                'sale_price' => $retail,
                'wholesale_price' => $wholesale,
                'purchase_price' => $cost,
                'allows_delivery' => $delivery,
                'unit_code' => 'NIU',
                'is_web_visible' => false,
            ]);
            $product->branches()->syncWithoutDetaching([$branch->id]);

            VariantGenerator::generate(
                $product,
                Color::whereIn('code', $colorCodes)->pluck('id')->all(),
                Size::whereIn('name', $sizeNames)->pluck('id')->all(),
            );

            // XXL / talla más grande un poco más cara, para mostrar precio propio por variante
            $product->variants()->whereIn('size', ['XL', '36'])->update(['sale_price' => $retail + 5]);

            foreach ($product->variants()->get() as $i => $variant) {
                if (StockService::available($variant->id, $warehouse->id) > 0) {
                    continue;
                }
                // Algunas tallas quedan bajas o agotadas para ver las alertas
                $qty = match (true) {
                    $i % 7 === 3 => 0,
                    $i % 5 === 1 => 3,
                    default => $stock,
                };
                if ($qty > 0) {
                    StockService::move($variant->id, $warehouse->id, $qty, 'initial', null, 'Stock inicial (demo)');
                }
            }
        }

        // Delivery (servicio)
        $delivery = Product::firstOrCreate(['name' => 'Delivery Lima'], [
            'is_service' => true, 'sale_price' => 10, 'unit_code' => 'ZZ', 'is_web_visible' => false,
        ]);
        $delivery->branches()->syncWithoutDetaching([$branch->id]);
        if (!$delivery->variants()->exists()) {
            ProductVariant::create(['product_id' => $delivery->id, 'sku' => 'DELIVERY', 'size' => 'Único', 'color_name' => 'Único', 'sale_price' => 10]);
        }

        // Clientes
        Customer::firstOrCreate(['document_number' => '00000000'], ['document_type' => '1', 'name' => 'Clientes varios', 'customer_type' => 'minorista']);
        Customer::firstOrCreate(['document_number' => '20999999991'], [
            'document_type' => '6', 'name' => 'BOUTIQUE MAYORISTA DEMO S.A.C.', 'address' => 'Av. Gamarra 123, La Victoria',
            'customer_type' => 'mayorista',
        ]);

        // Un usuario por rol
        $password = env('DEMO_PASSWORD') ?: Str::random(12);
        foreach (['administrador', 'vendedor', 'almacenero', 'contador'] as $role) {
            $user = User::firstOrCreate(
                ['email' => "{$role}@demo.test"],
                ['name' => ucfirst($role) . ' Demo', 'password' => $password, 'branch_id' => $branch->id],
            );
            $user->syncRoles([$role]);
            if ($role === 'administrador' && blank($user->approval_pin)) {
                $user->update(['approval_pin' => env('DEMO_PIN', '1234')]);
            }
        }

        Auth::logout();

        $this->command?->info('✅ Datos demo: 4 modelos con variantes y stock, delivery, 2 clientes y usuarios por rol.');
        $this->command?->info('   Usuarios: administrador@demo.test, vendedor@demo.test, almacenero@demo.test, contador@demo.test');
        if (!env('DEMO_PASSWORD')) {
            $this->command?->warn("🔑 Contraseña de los usuarios demo (se muestra una sola vez): {$password}");
        }
        $this->command?->info('   PIN de aprobación del administrador demo: ' . (env('DEMO_PIN') ? '(el de DEMO_PIN)' : '1234'));
    }
}
