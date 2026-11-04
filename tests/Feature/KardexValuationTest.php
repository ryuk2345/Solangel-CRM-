<?php

namespace Tests\Feature;

use App\Exports\KardexExcelExport;
use App\Filament\Pages\KardexReport;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\KardexService;
use App\Services\PurchaseService;
use App\Services\StockService;
use Database\Seeders\DemoDataSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class KardexValuationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $admin;
    private Warehouse $warehouse;
    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('DEMO_PASSWORD=secret-demo');
        $this->seed(DemoDataSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->admin = User::where('email', 'administrador@demo.test')->firstOrFail();
        $this->actingAs($this->admin);

        $this->warehouse = Warehouse::where('is_default', true)->firstOrFail();
        $this->variant = ProductVariant::whereHas('product', fn ($p) => $p->where('is_service', false))->firstOrFail();
    }

    public function test_promedio_ponderado_movil_y_comprobacion_a_cero(): void
    {
        $category = Category::firstOrCreate(['name' => 'Test Cat'], ['slug' => 'test-cat']);
        $product = Product::create([
            'name' => 'Vestido Kardex Test',
            'category_id' => $category->id,
            'is_service' => false,
            'sale_price' => 50,
            'purchase_price' => 10,
        ]);
        $variant = ProductVariant::create([
            'product_id' => $product->id,
            'sku' => 'TEST-KARDEX-VAL-01',
            'barcode' => '7750000009999',
            'size' => 'M',
            'color_name' => 'Rojo',
            'sale_price' => 50,
            'purchase_price' => 10,
        ]);

        $supplier = Supplier::create(['name' => 'Proveedor Textil', 'document_number' => '20555555551']);

        // 1. Compra 1: 10 unidades a S/ 10.00
        $p1 = PurchaseService::register([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'document_type' => 'factura',
            'document_number' => 'F001-1001',
        ], [
            ['product_variant_id' => $variant->id, 'quantity' => 10, 'unit_cost' => 10.00],
        ]);

        $m1 = \App\Models\StockMovement::where('reference_type', $p1->getMorphClass())->where('reference_id', $p1->id)->firstOrFail();
        $this->assertEquals(10.00, (float) $m1->unit_cost);
        $this->assertEquals(100.00, (float) $m1->total_cost);
        $this->assertEquals(10.00, (float) $m1->balance_unit_cost);
        $this->assertEquals(100.00, (float) $m1->balance_total_cost);
        $this->assertSame('F001-1001', $m1->payment_document);

        // 2. Compra 2: 10 unidades a S/ 20.00 (saldo: 20 unid, total 300.00, prom 15.00)
        $p2 = PurchaseService::register([
            'supplier_id' => $supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'document_type' => 'factura',
            'document_number' => 'F001-1002',
        ], [
            ['product_variant_id' => $variant->id, 'quantity' => 10, 'unit_cost' => 20.00],
        ]);

        $m2 = \App\Models\StockMovement::where('reference_type', $p2->getMorphClass())->where('reference_id', $p2->id)->firstOrFail();
        $this->assertEquals(20.00, (float) $m2->unit_cost);
        $this->assertEquals(200.00, (float) $m2->total_cost);
        $this->assertEquals(15.00, (float) $m2->balance_unit_cost);
        $this->assertEquals(300.00, (float) $m2->balance_total_cost);

        // 3. Venta de 5 unidades: salen a costo promedio 15.00 -> costo 75.00, saldo 15 unid, P.T. 225.00, P.U. 15.00
        $customer = Customer::firstOrFail();
        $sale = \App\Models\Sale::create([
            'user_id' => $this->admin->id,
            'customer_id' => $customer->id,
            'branch_id' => $this->warehouse->branch_id,
            'warehouse_id' => $this->warehouse->id,
            'document_type' => '03',
            'serie' => 'B001',
            'correlative' => 5001,
            'issue_date' => now(),
            'subtotal' => 100,
            'igv' => 18,
            'total' => 118,
            'sunat_status' => 'aceptado',
        ]);
        $mSale = StockService::move($variant->id, $this->warehouse->id, -5, 'sale', $sale, 'Venta B001-5001', null, 'B001-5001');

        $this->assertEquals(15.00, (float) $mSale->unit_cost);
        $this->assertEquals(-75.00, (float) $mSale->total_cost);
        $this->assertEquals(15.00, (float) $mSale->balance_unit_cost);
        $this->assertEquals(225.00, (float) $mSale->balance_total_cost);
        $this->assertSame(15, (int) $mSale->balance_after);

        // 4. Anulación de compra p2: saca 10 unidades a costo 20.00 -> total -200.00, saldo 5 unid, P.T. 25.00, P.U. 5.00
        PurchaseService::void($p2, 'Devolución al proveedor');
        $mVoid = \App\Models\StockMovement::where('type', 'purchase_void')->where('reference_id', $p2->id)->firstOrFail();
        $this->assertEquals(20.00, (float) $mVoid->unit_cost);
        $this->assertEquals(-200.00, (float) $mVoid->total_cost);
        $this->assertEquals(25.00, (float) $mVoid->balance_total_cost);
        $this->assertEquals(5.00, (float) $mVoid->balance_unit_cost);

        // 5. Venta de las 5 restantes: stock queda en 0 y saldos valorizados estrictamente en 0.00
        $mSaleEnd = StockService::move($variant->id, $this->warehouse->id, -5, 'sale', $sale, 'Venta final B001-5001', null, 'B001-5001');
        $this->assertSame(0, (int) $mSaleEnd->balance_after);
        $this->assertEquals(0.00, (float) $mSaleEnd->balance_total_cost);
        $this->assertEquals(0.00, (float) $mSaleEnd->balance_unit_cost);
    }

    public function test_kardex_reporte_y_cuadro_de_comprobacion_diferencia_cero(): void
    {
        $report = KardexService::getReport([
            'period' => now()->format('Y-m'),
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->variant->product_id,
        ]);

        $this->assertNotEmpty($report['categories']);
        $this->assertEquals(0.00, (float) $report['consolidated']['difference']);

        foreach ($report['categories'] as $cat => $blocks) {
            foreach ($blocks as $block) {
                // Inv Inicial + Compras - Inv Final = Costo de ventas
                $summary = $block['summary'];
                $check = round(($summary['initial_cost'] + $summary['entries_cost'] - $summary['final_cost']) - $summary['cost_of_sales'], 2);
                $this->assertEquals(0.00, $check);
                $this->assertEquals(0.00, (float) $summary['difference']);
            }
        }
    }

    public function test_pantalla_kardex_report_y_exportacion_a_excel(): void
    {
        // 1. Pantalla abre correctamente como administrador
        Livewire::test(KardexReport::class)
            ->assertOk()
            ->assertSee('Kardex valorizado')
            ->assertSee('Movimientos (auditoría)')
            ->assertSee('Exportar Excel (.xlsx)');

        // 2. Exportación a Excel genera respuesta descargable
        $report = KardexService::getReport(['period' => now()->format('Y-m')]);
        $response = KardexExcelExport::download($report);
        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
    }

    public function test_comando_revalorizar_kardex_es_idempotente(): void
    {
        $code = $this->artisan('kardex:revalorizar')->assertSuccessful()->run();
        $this->assertSame(0, $code);

        // Segunda corrida no altera saldos ni genera errores
        $code2 = $this->artisan('kardex:revalorizar')->assertSuccessful()->run();
        $this->assertSame(0, $code2);
    }

    public function test_almacenero_no_ve_costos_en_kardex(): void
    {
        $almacenero = User::where('email', 'almacenero@demo.test')->firstOrFail();
        $this->actingAs($almacenero);

        $component = Livewire::test(KardexReport::class)->assertOk();
        $this->assertFalse($component->instance()->canViewCosts());
    }
}
