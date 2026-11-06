<?php

namespace Tests\Feature;

use App\Filament\Resources\SaleResource\Pages\CreateSale;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Serie;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\CreditService;
use App\Services\SaleReemissionService;
use App\Services\StockService;
use App\Services\SunatService;
use Database\Seeders\DemoDataSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * Suite de Pruebas QA para Ventas, SUNAT (Mock) y Créditos (VEN-01 a VEN-07).
 */
class QaSalesSunatTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $admin;
    private User $vendedor;
    private Branch $branch;
    private CashRegister $caja;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('DEMO_PASSWORD=secret-demo');
        $this->seed(DemoDataSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        // REGLA DE SEGURIDAD: Confirmar sunat_sandbox = 1
        $setting = CompanySetting::first();
        $this->assertNotNull($setting, 'CompanySetting debe existir');
        $this->assertTrue((bool)$setting->sunat_sandbox, 'sunat_sandbox DEBE ser 1');

        $this->admin = User::where('email', 'administrador@demo.test')->firstOrFail();
        $this->vendedor = User::where('email', 'vendedor@demo.test')->firstOrFail();
        $this->branch = Branch::firstOrFail();

        // Asegurar caja abierta para el administrador y vendedor
        $this->caja = CashRegister::firstOrCreate(
            ['user_id' => $this->admin->id, 'branch_id' => $this->branch->id, 'status' => 'abierta'],
            ['opened_at' => now()]
        );

        $this->actingAs($this->admin);
    }

    /**
     * Helper para preparar ítems de venta con stock garantizado.
     */
    private function prepareSaleItems(int $count = 1, int $qty = 1): array
    {
        $polo = Product::where('name', 'Polo básico algodón pima')->firstOrFail();
        $variants = $polo->variants()->take($count)->get();
        $warehouse = $this->branch->warehouses()->firstOrFail();

        $items = [];
        $i = 0;
        foreach ($variants as $variant) {
            StockService::move($variant->id, $warehouse->id, 50, 'initial');

            $items["l{$i}"] = [
                'product_variant_id' => $variant->id,
                'product_name' => $variant->display_name,
                'quantity' => $qty,
                'price' => (float)$variant->sale_price,
                'total' => (float)$variant->sale_price * $qty,
            ];
            $i++;
        }

        return $items;
    }

    /**
     * VEN-01: Emisión Boleta (03) con cliente DNI / genérico y respuesta CDR aceptada por mock.
     */
    public function test_ven_01_emision_boleta_cliente_dni_mock_sunat(): void
    {
        $clienteMinorista = Customer::where('customer_type', 'minorista')->firstOrFail();
        $clienteMinorista->update([
            'document_type' => '1',
            'document_number' => '45678901',
            'name' => 'Cliente Boleta DNI',
        ]);

        $mockSunat = Mockery::mock(SunatService::class);
        $mockSunat->shouldReceive('enviarBoleta')
            ->once()
            ->withArgs(function (Sale $sale) {
                return str_starts_with($sale->serie, 'B')
                    && $sale->customer !== null
                    && $sale->customer->document_type === '1';
            })
            ->andReturn([
                'success' => true,
                'notes' => ['La Boleta de venta electrónica ha sido aceptada.'],
            ]);
        $this->app->instance(SunatService::class, $mockSunat);

        $items = $this->prepareSaleItems(1, 1);
        $totalVenta = array_sum(array_column($items, 'total'));

        $page = Livewire::test(CreateSale::class);
        $page->set('data', array_merge($page->get('data'), [
            'customer_id' => $clienteMinorista->id,
            'branch_id' => $this->branch->id,
            'warehouse_id' => $this->branch->warehouses()->first()->id,
            'serie' => 'B001',
            'issue_date' => now()->format('Y-m-d H:i:s'),
            'seller_id' => $this->admin->id,
            'items' => $items,
            'payment_condition' => 'contado',
            'payment_method' => 'efectivo',
            'payments' => [['payment_method' => 'efectivo', 'amount' => $totalVenta]],
        ]))->call('create');

        $page->assertHasNoErrors();

        $sale = Sale::latest('id')->firstOrFail();
        $this->assertEquals('B001', $sale->serie);
        $this->assertEquals('03', $sale->document_type);
        $this->assertEquals('aceptado', $sale->sunat_status);
        $this->assertNotNull($sale->correlative);
    }

    /**
     * VEN-02: Emisión Factura (01) con cliente RUC (tipo doc 6) y respuesta CDR aceptada por mock.
     */
    public function test_ven_02_emision_factura_cliente_ruc_mock_sunat(): void
    {
        $clienteEmpresa = Customer::firstOrCreate(
            ['document_number' => '20601234567'],
            [
                'name' => 'TEXTIL MODA S.A.C.',
                'document_type' => '6',
                'customer_type' => 'mayorista',
                'address' => 'Av. Argentina 1234',
            ]
        );

        $mockSunat = Mockery::mock(SunatService::class);
        $mockSunat->shouldReceive('enviarBoleta')
            ->once()
            ->withArgs(function (Sale $sale) {
                return str_starts_with($sale->serie, 'F')
                    && $sale->customer !== null
                    && $sale->customer->document_type === '6'
                    && strlen($sale->customer->document_number) === 11;
            })
            ->andReturn([
                'success' => true,
                'notes' => ['La Factura electrónica ha sido aceptada.'],
            ]);
        $this->app->instance(SunatService::class, $mockSunat);

        $items = $this->prepareSaleItems(1, 2);
        $totalVenta = array_sum(array_column($items, 'total'));

        $page = Livewire::test(CreateSale::class);
        $page->set('data', array_merge($page->get('data'), [
            'customer_id' => $clienteEmpresa->id,
            'branch_id' => $this->branch->id,
            'warehouse_id' => $this->branch->warehouses()->first()->id,
            'serie' => 'F001',
            'issue_date' => now()->format('Y-m-d H:i:s'),
            'seller_id' => $this->admin->id,
            'items' => $items,
            'payment_condition' => 'contado',
            'payment_method' => 'efectivo',
            'payments' => [['payment_method' => 'efectivo', 'amount' => $totalVenta]],
        ]))->call('create');

        $page->assertHasNoErrors();

        $sale = Sale::latest('id')->firstOrFail();
        $this->assertEquals('F001', $sale->serie);
        $this->assertEquals('01', $sale->document_type);
        $this->assertEquals('aceptado', $sale->sunat_status);
        $this->assertNotNull($sale->correlative);
    }

    /**
     * VEN-03: Validación negativa: Factura (01) con cliente DNI es rechazada por validación.
     */
    public function test_ven_03_validacion_negativa_factura_con_dni_bloqueada(): void
    {
        $clienteDni = Customer::where('customer_type', 'minorista')->firstOrFail();
        $clienteDni->update([
            'document_type' => '1',
            'document_number' => '78945612',
            'name' => 'Persona Natural con DNI',
        ]);

        $items = $this->prepareSaleItems(1, 1);
        $totalVenta = array_sum(array_column($items, 'total'));

        $salesCountBefore = Sale::where('serie', 'F001')->count();

        $page = Livewire::test(CreateSale::class);
        $page->set('data', array_merge($page->get('data'), [
            'customer_id' => $clienteDni->id,
            'branch_id' => $this->branch->id,
            'warehouse_id' => $this->branch->warehouses()->first()->id,
            'serie' => 'F001',
            'issue_date' => now()->format('Y-m-d H:i:s'),
            'seller_id' => $this->admin->id,
            'items' => $items,
            'payment_condition' => 'contado',
            'payment_method' => 'efectivo',
            'payments' => [['payment_method' => 'efectivo', 'amount' => $totalVenta]],
        ]))->call('create');

        $page->assertHasErrors(['data.serie']);
        $this->assertEquals($salesCountBefore, Sale::where('serie', 'F001')->count(), 'No se debe haber creado la factura con DNI');
    }

    /**
     * VEN-04: Redondeo y cuadre de IGV: Ítems con precios impares (subtotal + igv == total sin descuadre).
     */
    public function test_ven_04_redondeo_y_cuadre_igv_subtotal_mas_igv_igual_total(): void
    {
        $clienteMinorista = Customer::where('customer_type', 'minorista')->firstOrFail();
        $warehouse = $this->branch->warehouses()->firstOrFail();
        $polo = Product::where('name', 'Polo básico algodón pima')->firstOrFail();
        $variant = $polo->variants()->firstOrFail();
        $variant->update(['sale_price' => 33.33, 'wholesale_price' => 33.33]);
        $polo->update(['sale_price' => 33.33, 'wholesale_price' => 33.33]);

        StockService::move($variant->id, $warehouse->id, 50, 'initial');

        // Ítems con precio impar: S/ 33.33 x 3 unidades = S/ 99.99
        $items = [
            'l0' => [
                'product_variant_id' => $variant->id,
                'product_name' => $variant->display_name,
                'quantity' => 3,
                'price' => 33.33,
                'total' => 99.99,
            ]
        ];

        $mockSunat = Mockery::mock(SunatService::class);
        $mockSunat->shouldReceive('enviarBoleta')->andReturn(['success' => true]);
        $this->app->instance(SunatService::class, $mockSunat);

        $page = Livewire::test(CreateSale::class);
        $page->set('data', array_merge($page->get('data'), [
            'customer_id' => $clienteMinorista->id,
            'branch_id' => $this->branch->id,
            'warehouse_id' => $warehouse->id,
            'serie' => 'B001',
            'issue_date' => now()->format('Y-m-d H:i:s'),
            'seller_id' => $this->admin->id,
            'items' => $items,
            'payment_condition' => 'contado',
            'payment_method' => 'efectivo',
            'payments' => [['payment_method' => 'efectivo', 'amount' => 99.99]],
        ]))->call('create');

        $sale = Sale::latest('id')->firstOrFail();
        $this->assertEquals(99.99, (float)$sale->total);

        // Cuadre matemático exacto: subtotal + igv == total
        $sumaSubtotalIgv = round((float)$sale->subtotal + (float)$sale->igv, 2);
        $this->assertEquals((float)$sale->total, $sumaSubtotalIgv, 'El subtotal más el IGV debe cuadrar exactamente con el total');
    }

    /**
     * VEN-05: Reemisión de venta rechazada genera nuevo correlativo sin duplicar movimientos de stock.
     */
    public function test_ven_05_reemision_comprobante_rechazado_genera_nuevo_correlativo_sin_duplicar_stock(): void
    {
        $warehouse = $this->branch->warehouses()->firstOrFail();
        $polo = Product::where('name', 'Polo básico algodón pima')->firstOrFail();
        $variant = $polo->variants()->firstOrFail();
        $cliente = Customer::firstOrFail();

        // 1. Stock inicial asegurado
        StockService::move($variant->id, $warehouse->id, 50, 'initial');
        $stockAntesVenta = (int) Inventory::where('product_variant_id', $variant->id)->where('warehouse_id', $warehouse->id)->value('stock');

        // 2. Venta previa que quedó en estado 'rechazado' ante SUNAT
        $serie = Serie::where('serie', 'B001')->where('branch_id', $this->branch->id)->firstOrFail();
        $correlativoRechazado = $serie->current_number;
        $serie->increment('current_number');

        $sale = Sale::create([
            'branch_id' => $this->branch->id,
            'warehouse_id' => $warehouse->id,
            'customer_id' => $cliente->id,
            'user_id' => $this->admin->id,
            'seller_id' => $this->admin->id,
            'document_type' => '03',
            'serie' => 'B001',
            'correlative' => $correlativoRechazado,
            'issue_date' => now()->subDay(),
            'total' => 50.00,
            'subtotal' => round(50.00 / 1.18, 2),
            'igv' => round(50.00 - round(50.00 / 1.18, 2), 2),
            'payment_condition' => 'contado',
            'payment_method' => 'efectivo',
            'sunat_status' => 'rechazado',
            'sunat_error' => 'SUNAT rechazó: Error simulado previo',
            'sunat_error_code' => '2000',
        ]);

        $sale->items()->create([
            'product_variant_id' => $variant->id,
            'product_name' => $variant->display_name,
            'quantity' => 1,
            'price' => 50.00,
            'igv' => round(50.00 - round(50.00 / 1.18, 2), 2),
            'total' => 50.00,
        ]);

        // Descuento único de stock por la venta original
        StockService::move($variant->id, $warehouse->id, -1, 'sale', $sale);

        $stockAntesReemision = (int) Inventory::where('product_variant_id', $variant->id)->where('warehouse_id', $warehouse->id)->value('stock');
        $movimientosAntes = StockMovement::where('product_variant_id', $variant->id)->count();

        // 3. Mock de SUNAT para la reemisión (éxito)
        $mockSunat = Mockery::mock(SunatService::class);
        $mockSunat->shouldReceive('enviarBoleta')
            ->once()
            ->withArgs(function (Sale $s) use ($correlativoRechazado) {
                // Debe enviarse con nuevo correlativo, distinto al rechazado
                return (int)$s->correlative !== (int)$correlativoRechazado;
            })
            ->andReturn(['success' => true, 'notes' => ['Reemisión aceptada por SUNAT']]);
        $this->app->instance(SunatService::class, $mockSunat);

        // 4. Ejecutar reemisión
        $reemissionService = app(SaleReemissionService::class);
        $result = $reemissionService->reemitirOReintentar($sale, $this->admin);

        $this->assertTrue($result['success']);
        $sale->refresh();

        // Verificar cambio de correlativo y estado
        $this->assertNotEquals($correlativoRechazado, $sale->correlative, 'El correlativo debe haber cambiado');
        $this->assertEquals('aceptado', $sale->sunat_status);

        // REGLA CRÍTICA: El stock y los movimientos de kardex NO se deben haber alterado
        $stockDespuesReemision = (int) Inventory::where('product_variant_id', $variant->id)->where('warehouse_id', $warehouse->id)->value('stock');
        $movimientosDespues = StockMovement::where('product_variant_id', $variant->id)->count();

        $this->assertEquals($stockAntesReemision, $stockDespuesReemision, 'El stock no debe modificarse durante la reemisión');
        $this->assertEquals($movimientosAntes, $movimientosDespues, 'No se deben duplicar movimientos de kardex al reemitir');
    }

    /**
     * VEN-06: Venta a crédito mayorista con límite y fecha de vencimiento válida.
     */
    public function test_ven_06_venta_credito_mayorista_con_limite_y_fecha_vencimiento(): void
    {
        $mayorista = Customer::where('customer_type', 'mayorista')->firstOrFail();
        $mayorista->update([
            'credit_enabled' => true,
            'credit_limit' => 2000.00,
            'credit_days' => 15,
        ]);

        $dueDate = CreditService::assertCanSellOnCredit($mayorista, 500.00, $this->vendedor);

        $this->assertEquals(
            now()->startOfDay()->addDays(15)->toDateString(),
            $dueDate->toDateString(),
            'La fecha de vencimiento debe calcularse a partir de credit_days'
        );
    }

    /**
     * VEN-07: Venta a crédito rechazada para cliente minorista o no habilitado.
     */
    public function test_ven_07_venta_credito_cliente_minorista_o_deshabilitado_es_rechazada(): void
    {
        // 1. Cliente minorista intenta crédito
        $minorista = Customer::where('customer_type', 'minorista')->firstOrFail();
        $minorista->update(['credit_enabled' => true]); // Aunque tenga el flag en true, si es minorista no puede

        $this->expectException(ValidationException::class);
        CreditService::assertCanSellOnCredit($minorista, 100.00, $this->vendedor);
    }

    /**
     * VEN-07b: Mayorista con crédito deshabilitado es rechazado.
     */
    public function test_ven_07b_mayorista_con_credito_deshabilitado_es_rechazado(): void
    {
        $mayorista = Customer::where('customer_type', 'mayorista')->firstOrFail();
        $mayorista->update(['credit_enabled' => false]);

        $this->expectException(ValidationException::class);
        CreditService::assertCanSellOnCredit($mayorista, 100.00, $this->vendedor);
    }

    /**
     * VEN-07c: Mayorista supera límite de crédito ante un vendedor normal (sin permiso manage_credit).
     */
    public function test_ven_07c_mayorista_supera_limite_credito_vendedor_rechazado(): void
    {
        $mayorista = Customer::where('customer_type', 'mayorista')->firstOrFail();
        $mayorista->update([
            'credit_enabled' => true,
            'credit_limit' => 100.00,
            'credit_days' => 7,
        ]);

        // Asegurar que vendedor no tiene manage_credit
        $this->assertFalse($this->vendedor->can('manage_credit'));

        $this->expectException(ValidationException::class);
        CreditService::assertCanSellOnCredit($mayorista, 500.00, $this->vendedor);
    }
}
