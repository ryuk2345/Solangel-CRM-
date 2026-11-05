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
            'credit_enabled' => true, 'credit_limit' => 3000, 'credit_days' => 30,
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

        // Ventas y devoluciones de demostración para el Kardex (solo en local / consola, no interfiere con tests unitarios que esperan 0 ventas)
        if (!app()->runningUnitTests() || env('SEED_DEMO_SALES', false)) {
            if (!\App\Models\Sale::where('serie', 'NV01')->where('correlative', 101)->exists()) {
            $custRetail = Customer::where('document_number', '00000000')->firstOrFail();
            $custWholesale = Customer::where('document_number', '20999999991')->firstOrFail();

            $polo = Product::where('name', 'Polo básico algodón pima')->firstOrFail();
            $poloVar = $polo->variants()->firstOrFail();

            $blusa = Product::where('name', 'Blusa lino manga corta')->firstOrFail();
            $blusaVar = $blusa->variants()->firstOrFail();

            $casaca = Product::where('name', 'Casaca rompevientos')->firstOrFail();
            $casacaVar = $casaca->variants()->firstOrFail();

            $jean = Product::where('name', 'Jean slim fit')->firstOrFail();
            $jeanVar = $jean->variants()->firstOrFail();

            // 1. Venta 1 (Minorista): 2 Polos básicos
            $total1 = 59.90 * 2;
            $subtotal1 = round($total1 / 1.18, 2);
            $igv1 = round($total1 - $subtotal1, 2);
            $sale1 = \App\Models\Sale::create([
                'user_id' => $admin->id,
                'customer_id' => $custRetail->id,
                'branch_id' => $branch->id,
                'warehouse_id' => $warehouse->id,
                'document_type' => '00',
                'serie' => 'NV01',
                'correlative' => 101,
                'issue_date' => now(),
                'subtotal' => $subtotal1,
                'igv' => $igv1,
                'total' => $total1,
                'payment_condition' => 'contado',
                'payment_method' => 'efectivo',
                'sunat_status' => 'interna',
            ]);
            $item1 = $sale1->items()->create([
                'product_variant_id' => $poloVar->id,
                'product_name' => $polo->name,
                'quantity' => 2,
                'price' => 59.90,
                'igv' => $igv1,
                'total' => $total1,
            ]);
            StockService::move(
                $poloVar->id,
                $warehouse->id,
                -2,
                'sale',
                $sale1,
                'Venta NV01-00000101',
                null,
                'NV01-00000101'
            );

            // 2. Devolución de cliente: 1 Polo devuelto por talla
            \App\Services\SaleExchangeService::register(
                $sale1,
                [$item1->id => 1],
                'devolucion',
                'Talla no adecuada'
            );

            // 3. Venta 2 (Minorista): 1 Blusa lino
            $total2 = 79.90;
            $subtotal2 = round($total2 / 1.18, 2);
            $igv2 = round($total2 - $subtotal2, 2);
            $sale2 = \App\Models\Sale::create([
                'user_id' => $admin->id,
                'customer_id' => $custRetail->id,
                'branch_id' => $branch->id,
                'warehouse_id' => $warehouse->id,
                'document_type' => '00',
                'serie' => 'NV01',
                'correlative' => 102,
                'issue_date' => now(),
                'subtotal' => $subtotal2,
                'igv' => $igv2,
                'total' => $total2,
                'payment_condition' => 'contado',
                'payment_method' => 'efectivo',
                'sunat_status' => 'interna',
            ]);
            $sale2->items()->create([
                'product_variant_id' => $blusaVar->id,
                'product_name' => $blusa->name,
                'quantity' => 1,
                'price' => 79.90,
                'igv' => $igv2,
                'total' => $total2,
            ]);
            StockService::move(
                $blusaVar->id,
                $warehouse->id,
                -1,
                'sale',
                $sale2,
                'Venta NV01-00000102',
                null,
                'NV01-00000102'
            );

            // 4. Venta 3 (Mayorista de 2+ prendas): 3 Casacas (S/ 120 c/u) + 2 Jeans (S/ 110 c/u) = 5 prendas
            $totalCasacas = 120.00 * 3;
            $totalJeans = 110.00 * 2;
            $total3 = $totalCasacas + $totalJeans; // 580.00
            $subtotal3 = round($total3 / 1.18, 2);
            $igv3 = round($total3 - $subtotal3, 2);
            $sale3 = \App\Models\Sale::create([
                'user_id' => $admin->id,
                'customer_id' => $custWholesale->id,
                'branch_id' => $branch->id,
                'warehouse_id' => $warehouse->id,
                'document_type' => '00',
                'serie' => 'NV01',
                'correlative' => 103,
                'issue_date' => now(),
                'subtotal' => $subtotal3,
                'igv' => $igv3,
                'total' => $total3,
                'payment_condition' => 'contado',
                'payment_method' => 'transferencia',
                'sunat_status' => 'interna',
            ]);
            $subCasacas = round($totalCasacas / 1.18, 2);
            $sale3->items()->create([
                'product_variant_id' => $casacaVar->id,
                'product_name' => $casaca->name,
                'quantity' => 3,
                'price' => 120.00,
                'igv' => round($totalCasacas - $subCasacas, 2),
                'total' => $totalCasacas,
            ]);
            StockService::move(
                $casacaVar->id,
                $warehouse->id,
                -3,
                'sale',
                $sale3,
                'Venta NV01-00000103',
                null,
                'NV01-00000103'
            );

            $subJeans = round($totalJeans / 1.18, 2);
            $sale3->items()->create([
                'product_variant_id' => $jeanVar->id,
                'product_name' => $jean->name,
                'quantity' => 2,
                'price' => 110.00,
                'igv' => round($totalJeans - $subJeans, 2),
                'total' => $totalJeans,
            ]);
            StockService::move(
                $jeanVar->id,
                $warehouse->id,
                -2,
                'sale',
                $sale3,
                'Venta NV01-00000103',
                null,
                'NV01-00000103'
            );

            // 5. Casos de atención y demostración operativa (solo demo local)
            $vendedor = User::where('email', 'vendedor@demo.test')->first();
            if ($vendedor) {
                if (!\App\Models\WholesalePriceRequest::where('status', 'pendiente')->exists()) {
                    \App\Models\WholesalePriceRequest::create([
                        'branch_id' => $branch->id,
                        'customer_id' => $custWholesale->id,
                        'requested_by' => $vendedor->id,
                        'items' => [['name' => 'Casaca rompevientos', 'quantity' => 1]],
                        'status' => 'pendiente',
                    ]);
                }

                if (!\App\Models\CashRegister::where('user_id', $vendedor->id)->where('status', 'abierta')->exists()) {
                    \App\Models\CashRegister::create([
                        'user_id' => $vendedor->id,
                        'branch_id' => $branch->id,
                        'status' => 'abierta',
                        'opened_at' => now()->startOfDay()->addHours(8),
                        'initial_amount' => 150.00,
                    ]);
                }
            }

            if (!\App\Models\Sale::where('serie', 'F001')->where('correlative', 101)->exists()) {
                \App\Models\Sale::create([
                    'user_id' => $admin->id,
                    'customer_id' => $custWholesale->id,
                    'branch_id' => $branch->id,
                    'warehouse_id' => $warehouse->id,
                    'document_type' => '01',
                    'serie' => 'F001',
                    'correlative' => 101,
                    'issue_date' => now()->subDays(15),
                    'due_date' => now()->subDays(5),
                    'subtotal' => 300.00,
                    'igv' => 54.00,
                    'total' => 354.00,
                    'credit_balance' => 354.00,
                    'payment_condition' => 'credito',
                    'payment_method' => 'transferencia',
                    'sunat_status' => 'aceptado',
                ]);
            }

            // Traspaso por recibir en la tienda
            $otherBranch = \App\Models\Branch::where('id', '!=', $branch->id)->first();
            if ($otherBranch && !\App\Models\StockTransfer::where('destination_branch_id', $branch->id)->where('status', 'en_transito')->exists()) {
                $otherWh = \App\Models\Warehouse::where('branch_id', $otherBranch->id)->first();
                $transfer = \App\Models\StockTransfer::create([
                    'origin_branch_id' => $otherBranch->id,
                    'origin_warehouse_id' => $otherWh?->id ?? $warehouse->id,
                    'destination_branch_id' => $branch->id,
                    'destination_warehouse_id' => $warehouse->id,
                    'sent_by' => $admin->id,
                    'status' => 'en_transito',
                    'sent_at' => now()->subHours(2),
                    'transfer_number' => 'TR-00001',
                ]);
                $transfer->items()->create([
                    'product_variant_id' => $poloVar->id,
                    'quantity_sent' => 10,
                ]);
            }

            // Variantes con stock bajo para demostrar "Reponer primero" con días de stock
            \App\Models\Inventory::where('product_variant_id', $poloVar->id)->where('warehouse_id', $warehouse->id)->update(['stock' => 4]);
            \App\Models\Inventory::where('product_variant_id', $blusaVar->id)->where('warehouse_id', $warehouse->id)->update(['stock' => 6]);
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
