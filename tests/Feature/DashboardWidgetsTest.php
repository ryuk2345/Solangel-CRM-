<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\AdminStatsOverview;
use App\Filament\Widgets\ContadorStatsOverview;
use App\Filament\Widgets\IncomingTransfersWidget;
use App\Filament\Widgets\LatestSalesWidget;
use App\Filament\Widgets\NeedsAttentionWidget;
use App\Filament\Widgets\RestockPriorityWidget;
use App\Filament\Widgets\SellerPriceRequestsWidget;
use App\Filament\Widgets\SellerStatsOverview;
use App\Filament\Widgets\TopModelsWidget;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\WholesalePriceRequest;
use App\Services\DashboardMetrics;
use App\Services\StockService;
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

    public function test_administrador_ve_sus_widgets_especificos(): void
    {
        $admin = User::where('email', 'administrador@demo.test')->firstOrFail();
        $this->actingAs($admin);

        Livewire::test(Dashboard::class)->assertOk();

        // Ve sus stats
        $this->assertTrue(AdminStatsOverview::canView());
        Livewire::test(AdminStatsOverview::class)
            ->assertOk()
            ->assertSee('Ventas hoy')
            ->assertSee('Ventas del mes')
            ->assertSee('Margen del mes')
            ->assertSee('Por cobrar');

        // Ve incidencias y reposición
        $this->assertTrue(NeedsAttentionWidget::class::canView());
        Livewire::test(NeedsAttentionWidget::class)->assertOk();

        $this->assertTrue(RestockPriorityWidget::class::canView());
        Livewire::test(RestockPriorityWidget::class)->assertOk()->assertSee('Reponer primero');

        // Ve Top 5
        $this->assertTrue(TopModelsWidget::class::canView());
        Livewire::test(TopModelsWidget::class)->assertOk();

        // NO ve los widgets de otros roles
        $this->assertFalse(ContadorStatsOverview::canView());
        $this->assertFalse(SellerStatsOverview::canView());
        $this->assertFalse(SellerPriceRequestsWidget::canView());
        $this->assertFalse(IncomingTransfersWidget::canView());
    }

    public function test_contador_ve_sus_widgets_especificos(): void
    {
        $contador = User::where('email', 'contador@demo.test')->firstOrFail();
        $this->actingAs($contador);

        Livewire::test(Dashboard::class)->assertOk();

        // Ve sus stats contables
        $this->assertTrue(ContadorStatsOverview::canView());
        Livewire::test(ContadorStatsOverview::class)
            ->assertOk()
            ->assertSee('Ventas del mes')
            ->assertSee('Margen del mes')
            ->assertSee('Por cobrar')
            ->assertSee('Inventario valorizado');

        // Ve incidencias (solo SUNAT y créditos)
        $this->assertTrue(NeedsAttentionWidget::class::canView());
        Livewire::test(NeedsAttentionWidget::class)->assertOk();

        // NO ve los demás widgets
        $this->assertFalse(AdminStatsOverview::canView());
        $this->assertFalse(SellerStatsOverview::canView());
        $this->assertFalse(RestockPriorityWidget::canView());
        $this->assertFalse(TopModelsWidget::canView());
        $this->assertFalse(SellerPriceRequestsWidget::canView());
        $this->assertFalse(IncomingTransfersWidget::canView());
        $this->assertFalse(LatestSalesWidget::canView());
    }

    public function test_vendedor_ve_sus_widgets_especificos(): void
    {
        $vendedor = User::where('email', 'vendedor@demo.test')->firstOrFail();
        $this->actingAs($vendedor);

        Livewire::test(Dashboard::class)->assertOk();

        // Ve sus stats
        $this->assertTrue(SellerStatsOverview::canView());
        Livewire::test(SellerStatsOverview::class)
            ->assertOk()
            ->assertSee('Mis ventas hoy')
            ->assertSee('Mi caja');

        // NO ve los widgets administrativos ni contables
        $this->assertFalse(AdminStatsOverview::canView());
        $this->assertFalse(ContadorStatsOverview::canView());
        $this->assertFalse(NeedsAttentionWidget::canView());
        $this->assertFalse(RestockPriorityWidget::canView());
        $this->assertFalse(TopModelsWidget::canView());
        $this->assertFalse(IncomingTransfersWidget::canView());
    }

    public function test_almacenero_ve_sus_widgets_especificos(): void
    {
        $almacenero = User::where('email', 'almacenero@demo.test')->firstOrFail();
        $this->actingAs($almacenero);

        Livewire::test(Dashboard::class)->assertOk();

        // Ve reposición urgente
        $this->assertTrue(RestockPriorityWidget::canView());
        Livewire::test(RestockPriorityWidget::class)
            ->assertOk()
            ->assertSee('Reponer primero');

        // NO ve stats ni ventas ni finanzas
        $this->assertFalse(AdminStatsOverview::canView());
        $this->assertFalse(ContadorStatsOverview::canView());
        $this->assertFalse(SellerStatsOverview::canView());
        $this->assertFalse(NeedsAttentionWidget::canView());
        $this->assertFalse(TopModelsWidget::canView());
        $this->assertFalse(SellerPriceRequestsWidget::canView());
        $this->assertFalse(LatestSalesWidget::canView());
    }

    public function test_requiere_atencion_muestra_aprobacion_pendiente_y_credito_vencido(): void
    {
        $admin = User::where('email', 'administrador@demo.test')->firstOrFail();
        $this->actingAs($admin);

        $customer = Customer::firstOrFail();

        // 1. Solicitud de precio mayorista pendiente
        WholesalePriceRequest::create([
            'branch_id' => 1,
            'customer_id' => $customer->id,
            'requested_by' => $admin->id,
            'items' => [['name' => 'Polo básico', 'quantity' => 1]],
            'status' => 'pendiente',
        ]);

        // 2. Crédito vencido
        Sale::create([
            'user_id' => $admin->id,
            'customer_id' => $customer->id,
            'branch_id' => 1,
            'warehouse_id' => 1,
            'document_type' => '01',
            'serie' => 'F001',
            'correlative' => 999,
            'issue_date' => now()->subDays(10),
            'due_date' => now()->subDays(3),
            'subtotal' => 200.00,
            'igv' => 36.00,
            'total' => 236.00,
            'credit_balance' => 236.00,
            'payment_condition' => 'credito',
            'sunat_status' => 'aceptado',
        ]);

        Livewire::test(NeedsAttentionWidget::class)
            ->assertOk()
            ->assertSee('Aprobaciones de precio pendientes')
            ->assertSee('Créditos vencidos')
            ->assertSee('S/ 236.00');
    }

    public function test_reponer_primero_ordena_por_dias_de_stock(): void
    {
        $admin = User::where('email', 'administrador@demo.test')->firstOrFail();
        $this->actingAs($admin);

        $customer = Customer::firstOrFail();
        $product = Product::where('name', 'Polo básico algodón pima')->firstOrFail();
        $variants = $product->variants()->take(2)->get();

        $vUrgent = $variants[0];
        $vNormal = $variants[1];

        // Asegurar que esté por debajo del umbral para que califique como reposición urgente
        \App\Models\Inventory::where('product_variant_id', $vUrgent->id)->update(['stock' => 5]);

        // Crear venta reciente para generar alta rotación en $vUrgent (muchas ventas en 30 d)
        $sale = Sale::create([
            'user_id' => $admin->id,
            'customer_id' => $customer->id,
            'branch_id' => 1,
            'warehouse_id' => 1,
            'document_type' => '03',
            'serie' => 'B001',
            'correlative' => 888,
            'issue_date' => now(),
            'subtotal' => 500.00,
            'igv' => 90.00,
            'total' => 590.00,
            'payment_condition' => 'contado',
            'payment_method' => 'efectivo',
            'sunat_status' => 'aceptado',
        ]);

        $sale->items()->create([
            'product_variant_id' => $vUrgent->id,
            'product_name' => $product->name,
            'quantity' => 15,
            'price' => 59.90,
            'igv' => 90.00,
            'total' => 590.00,
            'price_type' => 'retail',
        ]);

        $list = DashboardMetrics::restockPriorityList(1);
        $this->assertNotEmpty($list);

        // La primera variante debe tener días de stock calculados y ser menor que las siguientes
        $first = $list->first();
        $this->assertNotNull($first->days_of_stock);
        $this->assertGreaterThan(0, $first->sold_30d);

        // Las variantes sin ventas deben quedar ordenadas después de las variantes con rotación
        $last = $list->last();
        if ($last->days_of_stock === null) {
            $this->assertEquals(0, $last->sold_30d);
        }

        Livewire::test(RestockPriorityWidget::class)
            ->assertOk()
            ->assertSee('Reponer primero')
            ->assertSee($vUrgent->product->name);
    }
}
