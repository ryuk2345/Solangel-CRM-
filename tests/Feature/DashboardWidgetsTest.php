<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\InventoryStats;
use App\Filament\Widgets\PaymentMethodsChart;
use App\Filament\Widgets\PendingApprovalsStat;
use App\Filament\Widgets\SalesChart;
use App\Filament\Widgets\SalesStats;
use App\Filament\Widgets\TopSellingProductsWidget;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Models\WholesalePriceRequest;
use Database\Seeders\DemoDataSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardWidgetsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('DEMO_PASSWORD=secret-demo');
        $this->seed(DemoDataSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    private function createSampleSaleWithItem(User $user): void
    {
        $customer = Customer::firstOrFail();
        $product = Product::where('name', 'Polo básico algodón pima')->firstOrFail();
        $variant = $product->variants()->firstOrFail();

        $sale = Sale::create([
            'user_id' => $user->id,
            'customer_id' => $customer->id,
            'branch_id' => $user->branch_id ?? 1,
            'warehouse_id' => 1,
            'document_type' => '03',
            'serie' => 'B001',
            'correlative' => 991,
            'issue_date' => now(),
            'subtotal' => 100.00,
            'igv' => 18.00,
            'total' => 118.00,
            'payment_condition' => 'contado',
            'payment_method' => 'efectivo',
            'sunat_status' => 'aceptado',
        ]);

        $sale->items()->create([
            'product_variant_id' => $variant->id,
            'product_name' => $product->name,
            'quantity' => 2,
            'price' => 50.00,
            'igv' => 18.00,
            'total' => 118.00,
            'price_type' => 'retail',
        ]);
    }

    public function test_administrador_ve_sus_widgets_especificos(): void
    {
        $admin = User::where('email', 'administrador@demo.test')->firstOrFail();
        $this->actingAs($admin);
        $this->createSampleSaleWithItem($admin);

        Livewire::test(Dashboard::class)->assertOk();

        // Fila 1: Inventario y finanzas (3 tarjetas)
        $this->assertTrue(InventoryStats::canView());
        Livewire::test(InventoryStats::class)
            ->assertOk()
            ->assertSee('Prendas en stock')
            ->assertSee('Alertas de stock')
            ->assertSee('Por cobrar');

        // Fila 2: Ventas y aprobaciones
        $this->assertTrue(SalesStats::canView());
        Livewire::test(SalesStats::class)
            ->assertOk()
            ->assertSee('Ventas de hoy')
            ->assertSee('Ventas del mes')
            ->assertSee('Cajas abiertas');

        $this->assertTrue(PendingApprovalsStat::canView());
        Livewire::test(PendingApprovalsStat::class)
            ->assertOk()
            ->assertSee('Ventas por aprobar');

        // Fila 3: Gráficos
        $this->assertTrue(SalesChart::canView());
        Livewire::test(SalesChart::class)->assertOk();

        $this->assertTrue(PaymentMethodsChart::canView());
        Livewire::test(PaymentMethodsChart::class)->assertOk();

        // Fila 4: Más vendidos con columna de dinero Total (S/)
        $this->assertTrue(TopSellingProductsWidget::canView());
        Livewire::test(TopSellingProductsWidget::class)
            ->assertOk()
            ->assertSee('Total (S/)')
            ->assertSee('Polo básico algodón pima');
    }

    public function test_contador_ve_sus_widgets_especificos_y_filtro_tienda(): void
    {
        $contador = User::where('email', 'contador@demo.test')->firstOrFail();
        $this->actingAs($contador);

        // Puede filtrar por tienda en el dashboard
        Livewire::test(Dashboard::class)
            ->assertOk()
            ->assertSee('Tienda');

        // Ve inventario (con Por cobrar)
        $this->assertTrue(InventoryStats::canView());
        Livewire::test(InventoryStats::class)
            ->assertOk()
            ->assertSee('Prendas en stock')
            ->assertSee('Alertas de stock')
            ->assertSee('Por cobrar');

        // Ve ventas globales
        $this->assertTrue(SalesStats::canView());
        Livewire::test(SalesStats::class)
            ->assertOk()
            ->assertSee('Ventas de hoy')
            ->assertSee('Ventas del mes')
            ->assertSee('Cajas abiertas');

        // Ve gráficos de ventas e ingresos
        $this->assertTrue(SalesChart::canView());
        $this->assertTrue(PaymentMethodsChart::canView());

        // NO ve ventas por aprobar ni tabla de más vendidos
        $this->assertFalse(PendingApprovalsStat::canView());
        $this->assertFalse(TopSellingProductsWidget::canView());
    }

    public function test_vendedor_ve_sus_widgets_especificos(): void
    {
        $vendedor = User::where('email', 'vendedor@demo.test')->firstOrFail();
        $this->actingAs($vendedor);

        Livewire::test(Dashboard::class)->assertOk();

        // NO ve la Fila 1 de inventario general
        $this->assertFalse(InventoryStats::canView());

        // Fila 2: Ve Ventas hoy y Cajas abiertas (NO ve Ventas del mes global)
        $this->assertTrue(SalesStats::canView());
        Livewire::test(SalesStats::class)
            ->assertOk()
            ->assertSee('Ventas de hoy')
            ->assertSee('Cajas abiertas')
            ->assertDontSee('Ventas del mes');

        // Ve su tarjeta personalizada de aprobaciones
        $this->assertTrue(PendingApprovalsStat::canView());
        Livewire::test(PendingApprovalsStat::class)
            ->assertOk()
            ->assertSee('Mis ventas por aprobar');

        // Ve gráficos y más vendidos
        $this->assertTrue(SalesChart::canView());
        $this->assertTrue(PaymentMethodsChart::canView());
        $this->assertTrue(TopSellingProductsWidget::canView());
    }

    public function test_almacenero_ve_solo_inventario_sin_por_cobrar_y_tabla_sin_dinero(): void
    {
        $almacenero = User::where('email', 'almacenero@demo.test')->firstOrFail();
        $admin = User::where('email', 'administrador@demo.test')->firstOrFail();
        $this->createSampleSaleWithItem($admin);

        $this->actingAs($almacenero);

        Livewire::test(Dashboard::class)->assertOk();

        // Fila 1: Ve prendas y alertas, pero NUNCA Por cobrar
        $this->assertTrue(InventoryStats::canView());
        Livewire::test(InventoryStats::class)
            ->assertOk()
            ->assertSee('Prendas en stock')
            ->assertSee('Alertas de stock')
            ->assertDontSee('Por cobrar');

        // Fila 4: Ve la tabla de más vendidos pero SIN columna Total (S/)
        $this->assertTrue(TopSellingProductsWidget::canView());
        Livewire::test(TopSellingProductsWidget::class)
            ->assertOk()
            ->assertSee('Polo básico algodón pima')
            ->assertDontSee('Total (S/)');

        // NO ve métricas de ventas ni aprobaciones ni gráficos
        $this->assertFalse(SalesStats::canView());
        $this->assertFalse(PendingApprovalsStat::canView());
        $this->assertFalse(SalesChart::canView());
        $this->assertFalse(PaymentMethodsChart::canView());
    }

    public function test_tarjeta_ventas_por_aprobar_refleja_solicitudes_segun_rol(): void
    {
        $admin = User::where('email', 'administrador@demo.test')->firstOrFail();
        $vendedor1 = User::where('email', 'vendedor@demo.test')->firstOrFail();
        $customer = Customer::firstOrFail();

        $vendedor2 = User::firstOrCreate(
            ['email' => 'vendedor2@demo.test'],
            ['name' => 'Vendedor 2 Demo', 'password' => 'secret-demo', 'branch_id' => 1]
        );
        $vendedor2->syncRoles(['vendedor']);

        // Limpiar solicitudes pendientes previas para un test determinista
        WholesalePriceRequest::where('status', 'pendiente')->delete();

        // 1. Crear 1 solicitud pendiente pedida por $vendedor1 en tienda 1
        WholesalePriceRequest::create([
            'branch_id' => 1,
            'customer_id' => $customer->id,
            'requested_by' => $vendedor1->id,
            'items' => [['name' => 'Vestido floral', 'quantity' => 1]],
            'status' => 'pendiente',
        ]);

        // Administrador: ve 1 pendiente y mensaje "Esperando tu PIN"
        $this->actingAs($admin);
        Livewire::test(PendingApprovalsStat::class)
            ->assertOk()
            ->assertSee('Ventas por aprobar')
            ->assertSee('1')
            ->assertSee('Esperando tu PIN');

        // Vendedor 1 (quien la solicitó): ve 1 en "Mis ventas por aprobar" y "Esperando al administrador"
        $this->actingAs($vendedor1);
        Livewire::test(PendingApprovalsStat::class)
            ->assertOk()
            ->assertSee('Mis ventas por aprobar')
            ->assertSee('1')
            ->assertSee('Esperando al administrador');

        // Vendedor 2: ve 0 en "Mis ventas por aprobar" y "Nada pendiente"
        $this->actingAs($vendedor2);
        Livewire::test(PendingApprovalsStat::class)
            ->assertOk()
            ->assertSee('Mis ventas por aprobar')
            ->assertSee('0')
            ->assertSee('Nada pendiente');
    }
}
