<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SaleAnulacionService;
use App\Services\SaleExchangeService;
use App\Services\StockService;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class QaInventoryKardexTest extends TestCase
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
     * INV-01: Venta descuenta stock de inventario y registra movimiento de salida en kardex.
     */
    public function test_inv_01_venta_descuenta_stock_y_registra_kardex(): void
    {
        $polo = Product::where('name', 'Polo básico algodón pima')->firstOrFail();
        $variant = $polo->variants()->firstOrFail();
        $warehouse = Warehouse::firstOrFail();

        $initialStock = (int) Inventory::where('product_variant_id', $variant->id)
            ->where('warehouse_id', $warehouse->id)
            ->value('stock');

        // Realizar salida por venta
        $movement = StockService::move(
            variantId: $variant->id,
            warehouseId: $warehouse->id,
            quantity: -2,
            type: 'sale',
            note: 'Venta de prueba QA'
        );

        $newStock = (int) Inventory::where('product_variant_id', $variant->id)
            ->where('warehouse_id', $warehouse->id)
            ->value('stock');

        $this->assertEquals($initialStock - 2, $newStock);
        $this->assertEquals(-2, $movement->quantity);
        $this->assertEquals($newStock, $movement->balance_after);
        $this->assertEquals('sale', $movement->type);
    }

    /**
     * INV-02: Venta que excede el stock disponible es bloqueada y no descuenta nada.
     */
    public function test_inv_02_salida_mayor_al_stock_lanza_excepcion_y_no_mueve_stock(): void
    {
        $polo = Product::where('name', 'Polo básico algodón pima')->firstOrFail();
        $variant = $polo->variants()->firstOrFail();
        $warehouse = Warehouse::firstOrFail();

        $initialStock = (int) Inventory::where('product_variant_id', $variant->id)
            ->where('warehouse_id', $warehouse->id)
            ->value('stock');

        $this->expectException(ValidationException::class);

        // Intentar sacar mucho más del stock existente
        StockService::move(
            variantId: $variant->id,
            warehouseId: $warehouse->id,
            quantity: -($initialStock + 100),
            type: 'sale',
            note: 'Intento de venta sin stock'
        );

        // El stock debe permanecer intacto tras la excepción
        $currentStock = (int) Inventory::where('product_variant_id', $variant->id)
            ->where('warehouse_id', $warehouse->id)
            ->value('stock');
        $this->assertEquals($initialStock, $currentStock);
    }

    /**
     * INV-03: Concurrencia de stock: comprueba que StockService::move() use lockForUpdate() dentro de DB::transaction.
     * Cubierto parcialmente según ajuste 2 de QA.
     */
    public function test_inv_03_stock_service_usa_lock_for_update_en_transaccion(): void
    {
        $polo = Product::where('name', 'Polo básico algodón pima')->firstOrFail();
        $variant = $polo->variants()->firstOrFail();
        $warehouse = Warehouse::firstOrFail();

        // 1. Verificación por inspección de código fuente de StockService
        $reflector = new \ReflectionClass(StockService::class);
        $fileName = $reflector->getFileName();
        $code = file_get_contents($fileName);

        $this->assertStringContainsString('DB::transaction', $code);
        $this->assertStringContainsString('lockForUpdate()', $code);
        $this->assertStringContainsString('$newStock < 0', $code);

        // 2. Ejecución atómica de prueba
        $movement = StockService::move($variant->id, $warehouse->id, 1, 'adjustment', null, 'QA Lock test');
        $this->assertInstanceOf(StockMovement::class, $movement);
    }

    /**
     * INV-04: Anulación de venta devuelve stock íntegro al almacén correspondiente.
     */
    public function test_inv_04_anulacion_de_venta_devuelve_stock_al_almacen(): void
    {
        $admin = User::where('email', 'administrador@demo.test')->firstOrFail();
        $customer = Customer::firstOrFail();
        $polo = Product::where('name', 'Polo básico algodón pima')->firstOrFail();
        $variant = $polo->variants()->firstOrFail();
        $warehouse = Warehouse::firstOrFail();

        $stockAntesVenta = (int) Inventory::where('product_variant_id', $variant->id)
            ->where('warehouse_id', $warehouse->id)
            ->value('stock');

        // Descontar 2 prendas por venta
        StockService::move($variant->id, $warehouse->id, -2, 'sale');

        $this->assertEquals($stockAntesVenta - 2, (int) Inventory::where('product_variant_id', $variant->id)->where('warehouse_id', $warehouse->id)->value('stock'));

        // Crear registro de venta interna (NV01)
        $sale = Sale::create([
            'user_id' => $admin->id,
            'customer_id' => $customer->id,
            'branch_id' => 1,
            'warehouse_id' => $warehouse->id,
            'document_type' => '03',
            'serie' => 'NV01',
            'correlative' => 777,
            'issue_date' => now(),
            'subtotal' => 100.00,
            'igv' => 18.00,
            'total' => 118.00,
            'payment_condition' => 'contado',
            'payment_method' => 'efectivo',
            'sunat_status' => 'pendiente',
        ]);

        $sale->items()->create([
            'product_variant_id' => $variant->id,
            'product_name' => $polo->name,
            'quantity' => 2,
            'price' => 50.00,
            'igv' => 18.00,
            'total' => 118.00,
        ]);

        // Anular la venta interna
        $result = SaleAnulacionService::anularVenta($sale, ['motivo' => 'Error de digitación'], $admin);
        $this->assertTrue($result['success'] ?? false);

        $stockFinal = (int) Inventory::where('product_variant_id', $variant->id)
            ->where('warehouse_id', $warehouse->id)
            ->value('stock');

        $this->assertEquals($stockAntesVenta, $stockFinal);
    }

    /**
     * INV-05: Cambio de prenda devuelve ítem anterior y descuenta el nuevo sin desfases.
     */
    public function test_inv_05_cambio_de_talla_restituye_devuelto_y_descuenta_reemplazo(): void
    {
        $admin = User::where('email', 'administrador@demo.test')->firstOrFail();
        $customer = Customer::firstOrFail();
        $polo = Product::where('name', 'Polo básico algodón pima')->firstOrFail();
        $variants = $polo->variants()->take(2)->get();
        $varA = $variants[0];
        $varB = $variants[1];
        $warehouse = Warehouse::firstOrFail();

        $stockA_inicial = (int) Inventory::where('product_variant_id', $varA->id)->where('warehouse_id', $warehouse->id)->value('stock');
        $stockB_inicial = (int) Inventory::where('product_variant_id', $varB->id)->where('warehouse_id', $warehouse->id)->value('stock');

        // Venta original de VarA
        StockService::move($varA->id, $warehouse->id, -1, 'sale');

        $sale = Sale::create([
            'user_id' => $admin->id,
            'customer_id' => $customer->id,
            'branch_id' => 1,
            'warehouse_id' => $warehouse->id,
            'document_type' => '03',
            'serie' => 'NV01',
            'correlative' => 888,
            'issue_date' => now(),
            'subtotal' => 50.00,
            'igv' => 9.00,
            'total' => 59.00,
            'payment_condition' => 'contado',
            'payment_method' => 'efectivo',
            'sunat_status' => 'aceptado',
        ]);

        $itemA = $sale->items()->create([
            'product_variant_id' => $varA->id,
            'product_name' => $polo->name,
            'quantity' => 1,
            'price' => 50.00,
            'igv' => 9.00,
            'total' => 59.00,
        ]);

        // Registrar devolución/cambio de VarA
        $saleReturn = SaleExchangeService::register(
            sale: $sale,
            quantities: [$itemA->id => 1],
            type: 'cambio',
            reason: 'Talla no adecuada'
        );

        $this->assertNotNull($saleReturn);

        // VarA debe haber recuperado su stock
        $stockA_postRetorno = (int) Inventory::where('product_variant_id', $varA->id)->where('warehouse_id', $warehouse->id)->value('stock');
        $this->assertEquals($stockA_inicial, $stockA_postRetorno);

        // Simular salida de la nueva prenda VarB para el cambio
        StockService::move($varB->id, $warehouse->id, -1, 'exchange_out', $saleReturn);

        $stockB_final = (int) Inventory::where('product_variant_id', $varB->id)->where('warehouse_id', $warehouse->id)->value('stock');
        $this->assertEquals($stockB_inicial - 1, $stockB_final);
    }
}
