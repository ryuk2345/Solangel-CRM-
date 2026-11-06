<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Color;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Size;
use App\Models\Warehouse;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QaCatalogAndVariantsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('DEMO_PASSWORD=secret-demo');
        $this->seed(DemoDataSeeder::class);
    }

    /**
     * PROD-01: Creación de variante con SKU y barcode únicos según modelo, color y talla.
     */
    public function test_prod_01_crear_variante_con_sku_y_barcode_unicos(): void
    {
        $category = Category::firstOrFail();
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Vestido Gala Noche',
            'code' => 'VGN',
            'sale_price' => 180.00,
            'wholesale_price' => 130.00,
            'is_service' => false,
        ]);

        $color = Color::firstOrCreate(['code' => 'NEG'], ['name' => 'Negro']);
        $size = Size::firstOrCreate(['name' => 'M']);

        $sku = "{$product->code}-{$color->code}-{$size->name}";
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'color_id' => $color->id,
            'size_id' => $size->id,
            'sku' => $sku,
            'barcode' => '7750000000018',
            'sale_price' => 180.00,
            'wholesale_price' => 130.00,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('product_variants', [
            'id' => $variant->id,
            'product_id' => $product->id,
            'sku' => $sku,
            'barcode' => '7750000000018',
        ]);
        $this->assertEquals(180.00, $variant->retail_price);
        $this->assertEquals(130.00, $variant->wholesale_price);
    }

    /**
     * PROD-02: Bloqueo de variante duplicada para la misma combinación modelo-color-talla.
     */
    public function test_prod_02_evitar_variante_duplicada_mismo_modelo_color_talla(): void
    {
        $product = Product::where('is_service', false)->firstOrFail();
        $color = Color::firstOrFail();
        $size = Size::firstOrFail();

        // Primera variante válida
        ProductVariant::firstOrCreate(
            [
                'product_id' => $product->id,
                'color_id' => $color->id,
                'size_id' => $size->id,
            ],
            [
                'sku' => "TEST-{$product->id}-{$color->id}-{$size->id}",
                'sale_price' => 50.00,
                'wholesale_price' => 35.00,
                'is_active' => true,
            ]
        );

        // Segundo intento con la misma combinación exacta debe lanzar QueryException por clave única
        $this->expectException(QueryException::class);

        ProductVariant::create([
            'product_id' => $product->id,
            'color_id' => $color->id,
            'size_id' => $size->id,
            'sku' => "TEST-DUP-{$product->id}-{$color->id}-{$size->id}",
            'sale_price' => 50.00,
            'wholesale_price' => 35.00,
            'is_active' => true,
        ]);
    }

    /**
     * PROD-03: Variante sin stock asignado tiene stock 0 y no inventario fantasma.
     */
    public function test_prod_03_variante_sin_stock_inicial_tiene_saldo_cero(): void
    {
        $product = Product::where('is_service', false)->firstOrFail();
        $color = Color::firstOrFail();
        $size = Size::firstOrCreate(['name' => 'XXL']);
        $warehouse = Warehouse::firstOrFail();

        $randomId = rand(1000, 9999);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'color_id' => $color->id,
            'size_id' => $size->id,
            'sku' => "TEST-ZERO-{$randomId}",
            'sale_price' => 70.00,
            'wholesale_price' => 50.00,
            'is_active' => true,
        ]);

        $inventory = Inventory::where('product_variant_id', $variant->id)
            ->where('warehouse_id', $warehouse->id)
            ->first();

        // O no existe registro aún o tiene stock 0
        $currentStock = $inventory ? (int) $inventory->stock : 0;
        $this->assertEquals(0, $currentStock);
    }

    /**
     * PROD-04: Producto marcado como servicio (delivery) no requiere tallas ni control de stock.
     */
    public function test_prod_04_servicio_delivery_no_requiere_tallas_ni_stock(): void
    {
        $delivery = Product::where('is_service', true)->first();

        if (! $delivery) {
            $category = Category::firstOrFail();
            $delivery = Product::create([
                'category_id' => $category->id,
                'name' => 'Servicio de Delivery',
                'is_service' => true,
                'sale_price' => 15.00,
            ]);
        }

        $this->assertTrue((bool) $delivery->is_service);
        // El servicio no debe tener stock físico en inventories
        $hasInventoryStock = Inventory::whereHas('productVariant', fn ($q) => $q->where('product_id', $delivery->id))
            ->where('stock', '>', 0)
            ->exists();
        $this->assertFalse($hasInventoryStock);
    }
}
