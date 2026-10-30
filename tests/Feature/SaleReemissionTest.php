<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Serie;
use App\Models\User;
use App\Services\SaleReemissionService;
use App\Services\SunatService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SaleReemissionTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected User $vendedorUser;
    protected Branch $branch;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // 🛡️ Regla Permanente: Confirmar que sunat_sandbox = 1
        $setting = CompanySetting::first();
        $this->assertNotNull($setting, 'CompanySetting debe existir');
        $this->assertEquals(1, (int)$setting->sunat_sandbox, 'sunat_sandbox debe ser 1 para ejecutar pruebas.');

        $this->adminUser = User::whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))->firstOrFail();
        $this->vendedorUser = User::whereHas('roles', fn ($q) => $q->where('name', 'vendedor'))->firstOrFail();
        $this->branch = Branch::find(1) ?? Branch::firstOrFail();
        $this->branch->update(['sunat_code' => '0000']);

        $branch2 = Branch::find(2);
        if ($branch2) {
            $branch2->update(['sunat_code' => '0002']);
            Serie::firstOrCreate(
                ['branch_id' => 2, 'document_type' => '01', 'serie' => 'F021'],
                ['current_number' => 200, 'is_active' => true]
            );
            Serie::firstOrCreate(
                ['branch_id' => 2, 'document_type' => '03', 'serie' => 'B021'],
                ['current_number' => 200, 'is_active' => true]
            );
        }

        $this->customer = Customer::whereRaw('LENGTH(TRIM(document_number)) = 11')->first() ?? Customer::firstOrFail();
    }

    /**
     * Prueba: Solo super_admin puede reemitir (ni vendedor ni un rol 'admin' pueden).
     */
    public function test_solo_super_admin_puede_reemitir(): void
    {
        $sale = Sale::create([
            'customer_id'      => $this->customer->id,
            'user_id'          => $this->adminUser->id,
            'branch_id'        => $this->branch->id,
            'document_type'    => '03',
            'serie'            => 'B020',
            'correlative'      => 999901,
            'issue_date'       => '2026-10-15 10:00:00',
            'total'            => 150.00,
            'subtotal'         => 127.12,
            'igv'              => 22.88,
            'payment_method'   => 'efectivo',
            'sunat_status'     => 'rechazado',
            'sunat_error'      => 'Error de prueba',
            'sunat_error_code' => '3239',
        ]);

        $service = new SaleReemissionService();

        // 1. Intento con cajero/vendedor
        $resVendedor = $service->reemitirOReintentar($sale, $this->vendedorUser);
        $this->assertFalse($resVendedor['success']);
        $this->assertStringContainsString('super_admin', $resVendedor['error']);

        // 2. Intento con un usuario que tenga rol 'admin' (no super_admin)
        $roleAdmin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $fakeAdmin = User::create([
            'name'      => 'Fake Admin',
            'email'     => 'fakeadmin_test@mangui.pe',
            'password'  => bcrypt('secret123'),
            'branch_id' => $this->branch->id,
        ]);
        $fakeAdmin->assignRole($roleAdmin);

        $resFakeAdmin = $service->reemitirOReintentar($sale, $fakeAdmin);
        $this->assertFalse($resFakeAdmin['success']);
        $this->assertStringContainsString('super_admin', $resFakeAdmin['error']);

        // Comprobante no fue modificado
        $sale->refresh();
        $this->assertEquals(999901, $sale->correlative);
        $this->assertEquals('rechazado', $sale->sunat_status);
    }

    /**
     * Prueba: Orden al reemitir en rechazados.
     * Guarda el número nuevo e historial antes de enviar; no toca stock ni caja ni created_at.
     */
    public function test_reemision_de_rechazado_guarda_historial_y_nuevo_numero_sin_tocar_stock(): void
    {
        $serieModel = Serie::where('document_type', '03')
            ->where('branch_id', $this->branch->id)
            ->where('is_active', true)
            ->first();

        if (!$serieModel) {
            $serieModel = Serie::create([
                'branch_id'      => $this->branch->id,
                'document_type'  => '03',
                'serie'          => 'B020',
                'current_number' => 1000,
                'is_active'      => 1,
            ]);
        }

        $correlativoInicialSerie = $serieModel->current_number;

        $sale = Sale::create([
            'customer_id'      => $this->customer->id,
            'user_id'          => $this->adminUser->id,
            'branch_id'        => $this->branch->id,
            'document_type'    => '03',
            'serie'            => $serieModel->serie,
            'correlative'      => 500,
            'issue_date'       => '2026-10-15 10:00:00',
            'total'            => 200.00,
            'subtotal'         => 169.49,
            'igv'              => 30.51,
            'payment_method'   => 'efectivo',
            'sunat_status'     => 'rechazado',
            'sunat_error'      => 'El establecimiento anexo no corresponde',
            'sunat_error_code' => '3239',
            'created_at'       => '2026-10-15 10:00:00',
        ]);

        $createdAtOriginal = $sale->created_at->toDateTimeString();
        $stockSnapshotAntes = Inventory::pluck('stock', 'id')->toArray();

        $mockSunat = $this->createMock(SunatService::class);
        $mockSunat->method('enviarBoleta')
            ->willReturn(['success' => true, 'notes' => []]);

        $this->app->instance(SunatService::class, $mockSunat);

        $service = new SaleReemissionService();
        $res = $service->reemitirOReintentar($sale, $this->adminUser);

        $this->assertTrue($res['success']);
        $this->assertEquals('reemitted_accepted', $res['action']);

        $sale->refresh();

        $this->assertEquals($correlativoInicialSerie, $sale->correlative);
        $this->assertEquals('aceptado', $sale->sunat_status);
        $this->assertEquals(now()->format('Y-m-d'), $sale->issue_date->format('Y-m-d'));

        $history = $sale->reemission_history;
        $this->assertIsArray($history);
        $this->assertCount(1, $history);
        $this->assertEquals(500, $history[0]['correlative']);
        $this->assertEquals('3239', $history[0]['sunat_error_code']);
        $this->assertEquals('rechazado', $history[0]['sunat_status']);
        $this->assertEquals($this->adminUser->id, $history[0]['reemitted_by']);

        $stockSnapshotDespues = Inventory::pluck('stock', 'id')->toArray();
        $this->assertEquals($stockSnapshotAntes, $stockSnapshotDespues);
        $this->assertEquals($createdAtOriginal, $sale->created_at->toDateTimeString());
    }

    /**
     * Prueba: Si el envío falla por conexión, la venta queda en "pendiente"
     * con el número nuevo y se reintenta con ese mismo número.
     */
    public function test_falla_conexion_al_reemitir_deja_venta_pendiente_con_nuevo_numero(): void
    {
        $serieModel = Serie::where('document_type', '03')
            ->where('branch_id', $this->branch->id)
            ->where('is_active', true)
            ->firstOrFail();

        $correlativoInicialSerie = $serieModel->current_number;

        $sale = Sale::create([
            'customer_id'      => $this->customer->id,
            'user_id'          => $this->adminUser->id,
            'branch_id'        => $this->branch->id,
            'document_type'    => '03',
            'serie'            => $serieModel->serie,
            'correlative'      => 600,
            'issue_date'       => '2026-10-15 10:00:00',
            'total'            => 100.00,
            'subtotal'         => 84.75,
            'igv'              => 15.25,
            'payment_method'   => 'efectivo',
            'sunat_status'     => 'rechazado',
            'sunat_error'      => 'Error 1032 comprobante previo',
            'sunat_error_code' => '1032',
        ]);

        $mockSunat = $this->createMock(SunatService::class);
        $mockSunat->method('enviarBoleta')
            ->willReturn(['error' => 'cURL error 28: Operation timed out']);

        $this->app->instance(SunatService::class, $mockSunat);

        $service = new SaleReemissionService();
        $res = $service->reemitirOReintentar($sale, $this->adminUser);

        $this->assertFalse($res['success']);
        $this->assertEquals('reemitted_pending', $res['action']);

        $sale->refresh();

        $this->assertEquals($correlativoInicialSerie, $sale->correlative);
        $this->assertEquals('pendiente', $sale->sunat_status);
        $this->assertNotNull($sale->reemission_history);
        $this->assertEquals(600, $sale->reemission_history[0]['correlative']);
    }

    /**
     * Prueba: Pendientes: reintentar siempre primero.
     */
    public function test_pendiente_reintenta_primero_y_si_es_aceptado_mantiene_su_numero(): void
    {
        $sale = Sale::create([
            'customer_id'    => $this->customer->id,
            'user_id'        => $this->adminUser->id,
            'branch_id'      => $this->branch->id,
            'document_type'  => '03',
            'serie'          => 'B020',
            'correlative'    => 777,
            'issue_date'     => '2026-10-15 10:00:00',
            'total'          => 120.00,
            'subtotal'       => 101.69,
            'igv'            => 18.31,
            'payment_method' => 'efectivo',
            'sunat_status'   => 'pendiente',
        ]);

        $mockSunat = $this->createMock(SunatService::class);
        $mockSunat->expects($this->once())
            ->method('enviarBoleta')
            ->with($this->anything(), true)
            ->willReturn(['success' => true]);

        $this->app->instance(SunatService::class, $mockSunat);

        $service = new SaleReemissionService();
        $res = $service->reemitirOReintentar($sale, $this->adminUser);

        $this->assertTrue($res['success']);
        $this->assertEquals('retry_accepted', $res['action']);

        $sale->refresh();
        $this->assertEquals(777, $sale->correlative);
        $this->assertEquals('aceptado', $sale->sunat_status);
        $this->assertNull($sale->reemission_history);
    }

    /**
     * Prueba: Pendiente que SUNAT responde 1033 (ya registrado)
     * Se marca como aceptado y NO se reemite para no duplicarlo.
     */
    public function test_pendiente_con_1033_se_marca_aceptado_sin_reemitir(): void
    {
        $sale = Sale::create([
            'customer_id'    => $this->customer->id,
            'user_id'        => $this->adminUser->id,
            'branch_id'      => $this->branch->id,
            'document_type'  => '01',
            'serie'          => 'F020',
            'correlative'    => 888,
            'issue_date'     => '2026-10-15 10:00:00',
            'total'          => 350.00,
            'subtotal'       => 296.61,
            'igv'            => 53.39,
            'payment_method' => 'transferencia',
            'sunat_status'   => 'pendiente',
        ]);

        $mockSunat = $this->createMock(SunatService::class);
        $mockSunat->method('enviarBoleta')
            ->willReturn([
                'success'            => true,
                'already_registered' => true,
                'notes'              => ['El comprobante ya se encontraba registrado en SUNAT.'],
            ]);

        $this->app->instance(SunatService::class, $mockSunat);

        $service = new SaleReemissionService();
        $res = $service->reemitirOReintentar($sale, $this->adminUser);

        $this->assertTrue($res['success']);
        $this->assertEquals('marked_accepted', $res['action']);

        $sale->refresh();
        $this->assertEquals(888, $sale->correlative);
        $this->assertEquals('aceptado', $sale->sunat_status);
        $this->assertNull($sale->reemission_history);
    }

    /**
     * Prueba: Pendiente que en reintento es rechazado (1032 o 2000-3999).
     * Se reemite con número nuevo y fecha de hoy.
     */
    public function test_pendiente_rechazado_por_sunat_se_reemite_con_numero_nuevo(): void
    {
        $serieModel = Serie::where('document_type', '03')
            ->where('branch_id', $this->branch->id)
            ->where('is_active', true)
            ->firstOrFail();

        $correlativoInicialSerie = $serieModel->current_number;

        $sale = Sale::create([
            'customer_id'    => $this->customer->id,
            'user_id'        => $this->adminUser->id,
            'branch_id'      => $this->branch->id,
            'document_type'  => '03',
            'serie'          => $serieModel->serie,
            'correlative'    => 999,
            'issue_date'     => '2026-10-15 10:00:00',
            'total'          => 180.00,
            'subtotal'       => 152.54,
            'igv'            => 27.46,
            'payment_method' => 'efectivo',
            'sunat_status'   => 'pendiente',
        ]);

        $mockSunat = $this->createMock(SunatService::class);
        $mockSunat->expects($this->exactly(2))
            ->method('enviarBoleta')
            ->willReturnOnConsecutiveCalls(
                [
                    'error'             => 'El comprobante ya fue informado como de baja o rechazado (1032)',
                    'rejected_by_sunat' => true,
                    'code'              => '1032',
                ],
                [
                    'success' => true,
                    'notes'   => [],
                ]
            );

        $this->app->instance(SunatService::class, $mockSunat);

        $service = new SaleReemissionService();
        $res = $service->reemitirOReintentar($sale, $this->adminUser);

        $this->assertTrue($res['success']);
        $this->assertEquals('reemitted_accepted', $res['action']);

        $sale->refresh();
        $this->assertEquals($correlativoInicialSerie, $sale->correlative);
        $this->assertEquals('aceptado', $sale->sunat_status);
        $this->assertEquals(now()->format('Y-m-d'), $sale->issue_date->format('Y-m-d'));

        $this->assertNotNull($sale->reemission_history);
        $this->assertEquals(999, $sale->reemission_history[0]['correlative']);
        $this->assertEquals('1032', $sale->reemission_history[0]['sunat_error_code']);
    }

    /**
     * Prueba: Si la línea en 0 es de un producto con stock, NO se toca ni se reemite.
     */
    public function test_venta_con_linea_precio_cero_de_producto_con_stock_no_se_toca_ni_reemite(): void
    {
        $stockVariant = ProductVariant::where('sku', '!=', 'REPE-GENERICO')
            ->whereHas('product', fn ($q) => $q->where('is_service', false))
            ->firstOrFail();

        $sale = Sale::create([
            'customer_id'      => $this->customer->id,
            'user_id'          => $this->adminUser->id,
            'branch_id'        => $this->branch->id,
            'document_type'    => '01',
            'serie'            => 'F001',
            'correlative'      => 7001,
            'issue_date'       => '2026-10-21 10:00:00',
            'total'            => 100.00,
            'subtotal'         => 84.75,
            'igv'              => 15.25,
            'payment_method'   => 'efectivo',
            'sunat_status'     => 'rechazado',
            'sunat_error'      => 'Error 3105',
            'sunat_error_code' => '3105',
        ]);

        SaleItem::create([
            'sale_id'            => $sale->id,
            'product_variant_id' => $stockVariant->id,
            'product_name'       => 'MANGUERA HIDRAULICA',
            'quantity'           => 1,
            'price'              => 100.00,
            'igv'                => 15.25,
            'total'              => 100.00,
        ]);

        $zeroItem = SaleItem::create([
            'sale_id'            => $sale->id,
            'product_variant_id' => $stockVariant->id,
            'product_name'       => 'ABRAZADERA REGALO CON STOCK',
            'quantity'           => 1,
            'price'              => 0.00,
            'igv'                => 0.00,
            'total'              => 0.00,
        ]);

        $service = new SaleReemissionService();
        $res = $service->reemitirOReintentar($sale, $this->adminUser);

        $this->assertFalse($res['success']);
        $this->assertEquals('zero_price_stock_blocked', $res['action']);
        $this->assertStringContainsString('producto con stock', $res['error']);

        // Verificar que la línea en 0 NO se borró y la venta NO cambió de número
        $sale->refresh();
        $this->assertEquals(7001, $sale->correlative);
        $this->assertEquals('rechazado', $sale->sunat_status);
        $this->assertCount(2, $sale->items);
        $this->assertDatabaseHas('sale_items', ['id' => $zeroItem->id]);
    }

    /**
     * Prueba: Si la descripción unida supera 255 caracteres, NO se corta ni se reemite.
     */
    public function test_venta_con_descripcion_unida_mayor_a_255_caracteres_no_se_corta_ni_reemite(): void
    {
        $genericVariant = ProductVariant::where('sku', 'REPE-GENERICO')->firstOrFail();

        $sale = Sale::create([
            'customer_id'      => $this->customer->id,
            'user_id'          => $this->adminUser->id,
            'branch_id'        => $this->branch->id,
            'document_type'    => '01',
            'serie'            => 'F001',
            'correlative'      => 7002,
            'issue_date'       => '2026-10-21 10:00:00',
            'total'            => 100.00,
            'subtotal'         => 84.75,
            'igv'              => 15.25,
            'payment_method'   => 'efectivo',
            'sunat_status'     => 'rechazado',
            'sunat_error'      => 'Error 3105',
            'sunat_error_code' => '3105',
        ]);

        $longDesc1 = str_repeat('A', 200);
        $longDesc2 = str_repeat('B', 60); // 200 + 3 (" - ") + 60 = 263 > 255

        SaleItem::create([
            'sale_id'            => $sale->id,
            'product_variant_id' => $genericVariant->id,
            'product_name'       => $longDesc1,
            'quantity'           => 1,
            'price'              => 100.00,
            'igv'                => 15.25,
            'total'              => 100.00,
        ]);

        $zeroItem = SaleItem::create([
            'sale_id'            => $sale->id,
            'product_variant_id' => $genericVariant->id,
            'product_name'       => $longDesc2,
            'quantity'           => 1,
            'price'              => 0.00,
            'igv'                => 0.00,
            'total'              => 0.00,
        ]);

        $service = new SaleReemissionService();
        $res = $service->reemitirOReintentar($sale, $this->adminUser);

        $this->assertFalse($res['success']);
        $this->assertEquals('merged_description_too_long', $res['action']);
        $this->assertStringContainsString('255 caracteres', $res['error']);

        // Verificar que no se cortó ni se tocó nada
        $sale->refresh();
        $this->assertEquals(7002, $sale->correlative);
        $this->assertCount(2, $sale->items);
        $this->assertEquals($longDesc1, $sale->items->first()->product_name);
        $this->assertDatabaseHas('sale_items', ['id' => $zeroItem->id]);
    }

    /**
     * Prueba: Facturas a crédito mantienen el mismo plazo (nuevo vencimiento = nueva emisión + días de crédito).
     */
    public function test_factura_a_credito_mantiene_mismo_plazo_de_dias_al_reemitir(): void
    {
        $serieModel = Serie::where('document_type', '01')
            ->where('branch_id', $this->branch->id)
            ->where('is_active', true)
            ->firstOrFail();

        $sale = Sale::create([
            'customer_id'      => $this->customer->id,
            'user_id'          => $this->adminUser->id,
            'branch_id'        => $this->branch->id,
            'document_type'    => '01',
            'serie'            => $serieModel->serie,
            'correlative'      => 7003,
            'issue_date'       => '2026-10-10 10:00:00',
            'total'            => 236.00,
            'subtotal'         => 200.00,
            'igv'              => 36.00,
            'payment_method'   => 'credito',
            'sunat_status'     => 'rechazado',
            'sunat_error'      => 'Error 3239',
            'sunat_error_code' => '3239',
        ]);

        // Plazo original: 15 días (del 10 de octubre al 25 de octubre)
        $sale->setAttribute('due_date', Carbon::parse('2026-10-25 10:00:00'));

        $mockSunat = $this->createMock(SunatService::class);
        $mockSunat->method('enviarBoleta')
            ->willReturnCallback(function (Sale $reemittedSale) {
                // Verificar que cuando se invoca a SunatService, due_date tiene el plazo de 15 días desde hoy
                $expectedDue = now()->addDays(15)->format('Y-m-d');
                $actualDue = Carbon::parse($reemittedSale->due_date)->format('Y-m-d');
                $this->assertEquals($expectedDue, $actualDue, 'El vencimiento debe mantener los 15 días de crédito.');
                return ['success' => true, 'notes' => []];
            });

        $this->app->instance(SunatService::class, $mockSunat);

        $service = new SaleReemissionService();
        $res = $service->reemitirOReintentar($sale, $this->adminUser);

        $this->assertTrue($res['success']);
        $this->assertEquals(now()->addDays(15)->format('Y-m-d'), Carbon::parse($sale->due_date)->format('Y-m-d'));
    }

    /**
     * Prueba REAL en Sandbox (SUNAT Beta, sunat_sandbox = 1) en AMBAS TIENDAS:
     * Una venta pendiente con línea en 0 de servicio/genérico pasa su texto a la línea anterior,
     * guarda todos los datos de la línea en 0 en el historial, quita la línea en 0 y SUNAT la acepta.
     */
    public function test_venta_pendiente_con_linea_en_cero_pasa_texto_y_sunat_la_acepta_en_ambas_tiendas(): void
    {
        $genericVariant = ProductVariant::where('sku', 'REPE-GENERICO')->firstOrFail();

        $branches = [
            1 => ['expected_annex' => '0000', 'expected_serie' => 'F020'],
            2 => ['expected_annex' => '0002', 'expected_serie' => 'F021'],
        ];

        foreach ($branches as $branchId => $expected) {
            $branch = Branch::findOrFail($branchId);
            $this->assertEquals($expected['expected_annex'], $branch->sunat_code);

            $serieModel = Serie::where('branch_id', $branchId)
                ->where('document_type', '01')
                ->where('is_active', true)
                ->firstOrFail();

            $this->assertEquals($expected['expected_serie'], $serieModel->serie);

            $correlativo = $serieModel->current_number;
            $serieModel->increment('current_number');

            $sale = Sale::create([
                'customer_id'    => $this->customer->id,
                'user_id'        => $this->adminUser->id,
                'branch_id'      => $branchId,
                'document_type'  => '01',
                'serie'          => $serieModel->serie,
                'correlative'    => $correlativo,
                'issue_date'     => now(),
                'total'          => 118.00,
                'subtotal'       => 100.00,
                'igv'            => 18.00,
                'payment_method' => 'efectivo',
                'sunat_status'   => 'pendiente',
            ]);

            $item1 = SaleItem::create([
                'sale_id'            => $sale->id,
                'product_variant_id' => $genericVariant->id,
                'product_name'       => 'MANGUERA + ABRAZADERAS + CHUCK',
                'quantity'           => 1,
                'price'              => 118.00,
                'igv'                => 18.00,
                'total'              => 118.00,
            ]);

            $itemZero = SaleItem::create([
                'sale_id'            => $sale->id,
                'product_variant_id' => $genericVariant->id,
                'product_name'       => 'PLACA: A0R806',
                'quantity'           => 1,
                'price'              => 0.00,
                'igv'                => 0.00,
                'total'              => 0.00,
            ]);

            $zeroId = $itemZero->id;

            // Ejecutar con el SunatService REAL apuntando al Sandbox Beta de SUNAT
            $this->app->forgetInstance(SunatService::class);
            $service = new SaleReemissionService();
            $res = $service->reemitirOReintentar($sale, $this->adminUser);
            if (!$res['success'] && str_contains($res['error'] ?? '', 'Unauthorized')) {
                sleep(1);
                $sale->refresh();
                $res = $service->reemitirOReintentar($sale, $this->adminUser);
            }

            $this->assertTrue($res['success'], "Fallo en Tienda {$branchId}: " . ($res['error'] ?? ''));

            $sale->refresh();
            $this->assertEquals('aceptado', $sale->sunat_status);

            // 1. La línea en 0 fue eliminada y su texto pasó al final de la línea 1
            $this->assertDatabaseMissing('sale_items', ['id' => $zeroId]);
            $this->assertCount(1, $sale->items);
            $this->assertEquals(
                'MANGUERA + ABRAZADERAS + CHUCK - PLACA: A0R806',
                $sale->items->first()->product_name
            );

            // 2. El historial guardó todos los datos de la línea en 0 antes de borrarla
            $this->assertNotEmpty($sale->reemission_history);
            $lastHistory = collect($sale->reemission_history)->last();
            $this->assertArrayHasKey('removed_zero_items', $lastHistory);
            $removedData = $lastHistory['removed_zero_items'][0];
            $this->assertEquals($zeroId, $removedData['id']);
            $this->assertNotEmpty($removedData['product']);
            $this->assertEquals('PLACA: A0R806', $removedData['product_name']);
            $this->assertEquals(1, $removedData['quantity']);
            $this->assertEquals(0.0, $removedData['price']);
            $this->assertEquals(0.0, $removedData['igv']);
            $this->assertEquals(0.0, $removedData['total']);

            // 3. Verificar XML y CDR generados y aceptados por SUNAT Beta
            $setting = CompanySetting::first();
            $correlativoPadded = str_pad($sale->correlative, 8, '0', STR_PAD_LEFT);
            $docName = "{$setting->ruc}-01-{$sale->serie}-{$correlativoPadded}";
            $xmlFullPath = storage_path("app/sunat/xmls/{$docName}.xml");
            $cdrFullPath = storage_path("app/sunat/cdrs/R-{$docName}.zip");

            $this->assertFileExists($xmlFullPath);
            $this->assertFileExists($cdrFullPath);
            $xmlContent = file_get_contents($xmlFullPath);
            $this->assertStringContainsString('MANGUERA + ABRAZADERAS + CHUCK - PLACA: A0R806', $xmlContent);
            $this->assertStringContainsString("<cbc:AddressTypeCode>{$expected['expected_annex']}</cbc:AddressTypeCode>", $xmlContent);
        }
    }

    /**
     * Prueba REAL en Sandbox (SUNAT Beta, sunat_sandbox = 1) en AMBAS TIENDAS:
     * Una venta rechazada (ej. F021-123 con error 3105/1032) se reemite con la serie
     * y el código de anexo de su tienda (0000 en Principal, 0002 en Tienda 2),
     * verifica impresión de QR/Hash y SUNAT la acepta.
     */
    public function test_venta_rechazada_se_reemite_con_serie_y_codigo_anexo_de_su_tienda_en_ambas_tiendas(): void
    {
        $genericVariant = ProductVariant::where('sku', 'REPE-GENERICO')->firstOrFail();

        $branches = [
            1 => ['expected_annex' => '0000', 'expected_serie' => 'F020', 'old_correlative' => 88123],
            2 => ['expected_annex' => '0002', 'expected_serie' => 'F021', 'old_correlative' => 88123],
        ];

        foreach ($branches as $branchId => $expected) {
            $branch = Branch::findOrFail($branchId);

            $serieModel = Serie::where('branch_id', $branchId)
                ->where('document_type', '01')
                ->where('is_active', true)
                ->firstOrFail();

            $expectedNewCorrelative = $serieModel->current_number;

            $sale = Sale::create([
                'customer_id'      => $this->customer->id,
                'user_id'          => $this->adminUser->id,
                'branch_id'        => $branchId,
                'document_type'    => '01',
                'serie'            => $expected['expected_serie'],
                'correlative'      => $expected['old_correlative'],
                'issue_date'       => '2026-10-21 15:30:00',
                'total'            => 118.00,
                'subtotal'         => 100.00,
                'igv'              => 18.00,
                'payment_method'   => 'efectivo',
                'sunat_status'     => 'rechazado',
                'sunat_error'      => 'El XML debe contener al menos un tributo por línea de afectación por IGV (3105)',
                'sunat_error_code' => '3105',
            ]);

            SaleItem::create([
                'sale_id'            => $sale->id,
                'product_variant_id' => $genericVariant->id,
                'product_name'       => 'MANGUERA + ABRAZADERAS + CHUCK',
                'quantity'           => 1,
                'price'              => 118.00,
                'igv'                => 18.00,
                'total'              => 118.00,
            ]);

            SaleItem::create([
                'sale_id'            => $sale->id,
                'product_variant_id' => $genericVariant->id,
                'product_name'       => 'PLACA: A0R806',
                'quantity'           => 1,
                'price'              => 0.00,
                'igv'                => 0.00,
                'total'              => 0.00,
            ]);

            $this->app->forgetInstance(SunatService::class);
            $service = new SaleReemissionService();
            $res = $service->reemitirOReintentar($sale, $this->adminUser);
            if (!$res['success'] && str_contains($res['error'] ?? '', 'Unauthorized')) {
                sleep(1);
                $sale->refresh();
                $res = $service->reemitirOReintentar($sale, $this->adminUser);
            }

            $this->assertTrue($res['success'], "Fallo al reemitir en Tienda {$branchId}: " . ($res['error'] ?? ''));
            $this->assertContains($res['action'], ['reemitted_accepted', 'retry_accepted']);

            $sale->refresh();

            // 1. Reemitida con la serie propia de su tienda y el correlativo nuevo
            $this->assertEquals($expected['expected_serie'], $sale->serie);
            $this->assertEquals($expectedNewCorrelative, $sale->correlative);
            $this->assertEquals('aceptado', $sale->sunat_status);
            $this->assertEquals(now()->format('Y-m-d'), $sale->issue_date->format('Y-m-d'));

            // 2. Verificar XML: Anexo SUNAT de la tienda (0000 o 0002), descripción unida y DigestValue (Hash para QR)
            $setting = CompanySetting::first();
            $correlativoPadded = str_pad($sale->correlative, 8, '0', STR_PAD_LEFT);
            $docName = "{$setting->ruc}-01-{$sale->serie}-{$correlativoPadded}";
            $xmlFullPath = storage_path("app/sunat/xmls/{$docName}.xml");
            $cdrFullPath = storage_path("app/sunat/cdrs/R-{$docName}.zip");

            $this->assertFileExists($xmlFullPath);
            $this->assertFileExists($cdrFullPath);
            $xmlContent = file_get_contents($xmlFullPath);
            $this->assertStringContainsString("<cbc:AddressTypeCode>{$expected['expected_annex']}</cbc:AddressTypeCode>", $xmlContent);
            $this->assertStringContainsString('MANGUERA + ABRAZADERAS + CHUCK - PLACA: A0R806', $xmlContent);
            $this->assertStringContainsString('DigestValue', $xmlContent);
        }
    }

    /**
     * Prueba de procesamiento en lote (Reemisión Masiva).
     */
    public function test_procesamiento_en_lote_reemite_correctamente(): void
    {
        $sales = collect();
        for ($i = 1; $i <= 3; $i++) {
            $sales->push(Sale::create([
                'customer_id'      => $this->customer->id,
                'user_id'          => $this->adminUser->id,
                'branch_id'        => $this->branch->id,
                'document_type'    => '03',
                'serie'            => 'B020',
                'correlative'      => 8000 + $i,
                'issue_date'       => '2026-10-15 10:00:00',
                'total'            => 50.00 * $i,
                'subtotal'         => 42.37 * $i,
                'igv'              => 7.63 * $i,
                'payment_method'   => 'efectivo',
                'sunat_status'     => 'rechazado',
                'sunat_error'      => 'Error 3239',
                'sunat_error_code' => '3239',
            ]));
        }

        $mockSunat = $this->createMock(SunatService::class);
        $mockSunat->method('enviarBoleta')
            ->willReturn(['success' => true, 'notes' => []]);

        $this->app->instance(SunatService::class, $mockSunat);

        $service = new SaleReemissionService();
        $res = $service->reemitirLote($sales, $this->adminUser);

        $this->assertEquals(3, $res['total']);
        $this->assertEquals(3, $res['reemitted_accepted']);
        $this->assertEquals(0, $res['failed']);
    }
}
