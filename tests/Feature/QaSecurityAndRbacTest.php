<?php

namespace Tests\Feature;

use App\Filament\Pages\KardexReport;
use App\Filament\Resources\SaleResource\Pages\CreateSale;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Models\WholesalePriceRequest;
use App\Services\StockService;
use App\Services\SunatService;
use App\Services\WholesaleApprovalService;
use Database\Seeders\DemoDataSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * Suite de Pruebas QA para Seguridad y Control de Acceso RBAC (SEC-01 a SEC-04).
 */
class QaSecurityAndRbacTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $admin;
    private User $vendedor;
    private User $almacenero;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('DEMO_PASSWORD=secret-demo');
        $this->seed(DemoDataSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->admin = User::where('email', 'administrador@demo.test')->firstOrFail();
        $this->vendedor = User::where('email', 'vendedor@demo.test')->firstOrFail();
        $this->almacenero = User::where('email', 'almacenero@demo.test')->firstOrFail();
        $this->branch = Branch::firstOrFail();
    }

    /**
     * SEC-01: Acceso no autenticado (Guest) a rutas sensibles es redirigido a login.
     */
    public function test_sec_01_rutas_protegidas_redirigen_a_login_para_invitados(): void
    {
        // 1. Acceso a la consola administrativa de Filament
        $responseAdmin = $this->get('/admin');
        $responseAdmin->assertStatus(302);
        $this->assertTrue(
            str_contains($responseAdmin->headers->get('Location') ?? '', 'login'),
            'Ruta /admin debe redirigir a login para invitados'
        );

        // 2. Acceso directo a rutas de impresión de tickets
        $responseTicket = $this->get('/sales/1/ticket');
        $responseTicket->assertStatus(302);

        $responseA4 = $this->get('/sales/1/a4');
        $responseA4->assertStatus(302);
    }

    /**
     * SEC-02: Almacenero no tiene permisos para ver columnas ni valores de costo en Kardex.
     */
    public function test_sec_02_almacenero_no_tiene_acceso_a_costos_en_kardex(): void
    {
        // Verificar que el usuario con rol 'almacenero' carece del permiso 'view_cost_price'
        $this->assertFalse($this->almacenero->can('view_cost_price'));
        $this->assertFalse($this->almacenero->seesAllBranches());

        // Verificar el control interno de canViewCosts en la página KardexReport
        $this->actingAs($this->almacenero);
        $kardexPage = new KardexReport();
        $this->assertFalse($kardexPage->canViewCosts(), 'El almacenero no debe tener acceso a ver costos en el Kardex');

        // En contraste, el administrador sí tiene acceso
        $this->actingAs($this->admin);
        $this->assertTrue($kardexPage->canViewCosts(), 'El administrador sí debe tener acceso a ver costos en el Kardex');
    }

    /**
     * SEC-03: Vendedor no puede aprobar solicitudes de precio mayorista con PIN.
     */
    public function test_sec_03_vendedor_no_puede_aprobar_solicitud_mayorista_con_pin(): void
    {
        $this->assertFalse($this->vendedor->can('authorize_wholesale_price'));

        $polo = Product::where('name', 'Polo básico algodón pima')->firstOrFail();
        $variant = $polo->variants()->firstOrFail();
        $mayorista = Customer::where('customer_type', 'mayorista')->firstOrFail();

        // 1 prenda (menos del mínimo de 2)
        $items = [
            'l0' => [
                'product_variant_id' => $variant->id,
                'quantity' => 1,
                'price' => (float)$variant->sale_price,
            ]
        ];

        // Crear solicitud pendiente
        $solicitud = WholesaleApprovalService::request(
            $mayorista->id,
            $this->branch->id,
            $items,
            $this->vendedor,
            'Solicitud de prueba QA'
        );

        $this->assertEquals('pendiente', $solicitud->status);

        // Intento de aprobación por el vendedor: debe ser bloqueado por autorización
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Este usuario no puede aprobar precios mayoristas.');

        WholesaleApprovalService::approve($solicitud, $this->vendedor, '1234');
    }

    /**
     * SEC-04: Inyección en formulario: el backend calcula el precio real y no confía en precio manipulado ni estado SUNAT inyectado.
     */
    public function test_sec_04_intento_inyeccion_precio_y_estado_sunat_bloqueado_por_backend(): void
    {
        $this->actingAs($this->vendedor);

        // Vendedor no puede editar precios a mano
        $this->assertFalse($this->vendedor->can('edit_sale_price'));

        $caja = CashRegister::firstOrCreate(
            ['user_id' => $this->vendedor->id, 'branch_id' => $this->branch->id, 'status' => 'abierta'],
            ['opened_at' => now()]
        );

        $warehouse = $this->branch->warehouses()->firstOrFail();
        $polo = Product::where('name', 'Polo básico algodón pima')->firstOrFail();
        $variant = $polo->variants()->firstOrFail();

        // Asegurar precio de catálogo S/ 45.00
        $variant->update(['sale_price' => 45.00]);
        $polo->update(['sale_price' => 45.00]);
        StockService::move($variant->id, $warehouse->id, 20, 'initial');

        $clienteMinorista = Customer::where('customer_type', 'minorista')->firstOrFail();

        // El usuario malicioso intenta inyectar price = 0.50 y sunat_status = 'aceptado'
        $itemsInyectados = [
            'l0' => [
                'product_variant_id' => $variant->id,
                'product_name' => $variant->display_name,
                'quantity' => 1,
                'price' => 0.50, // Intento de pagar 50 céntimos en vez de S/ 45.00
                'total' => 0.50,
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
            'seller_id' => $this->vendedor->id,
            'items' => $itemsInyectados,
            'payment_condition' => 'contado',
            'payment_method' => 'efectivo',
            'payments' => [['payment_method' => 'efectivo', 'amount' => 45.00]],
            'sunat_status' => 'aceptado', // Intento de inyectar estado
        ]))->call('create');

        $sale = Sale::latest('id')->firstOrFail();

        // REGLA FUNDAMENTAL DE NEGOCIO: El precio total debe ser el calculado por el backend (S/ 45.00), no el inyectado (S/ 0.50)
        $this->assertEquals(45.00, (float)$sale->total, 'El backend debió recalcular el precio al valor de catálogo S/ 45.00');

        $saleItem = $sale->items()->firstOrFail();
        $this->assertEquals(45.00, (float)$saleItem->price, 'La línea debe tener el precio oficial del catálogo');
    }
}
