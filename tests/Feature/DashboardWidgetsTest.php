<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\AdminFinancialStats;
use App\Filament\Widgets\InTransitTransfersWidget;
use App\Filament\Widgets\LatestSalesWidget;
use App\Filament\Widgets\SalesBreakdownChart;
use App\Filament\Widgets\ShopCashWidget;
use App\Filament\Widgets\StatsOverview;
use App\Filament\Widgets\StockBySizeWidget;
use App\Filament\Widgets\TopModelsWidget;
use App\Models\CashRegister;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockTransfer;
use App\Models\User;
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

    public function test_dashboard_y_widgets_abren_para_administrador(): void
    {
        $admin = User::where('email', 'administrador@demo.test')->firstOrFail();
        $this->actingAs($admin);

        Livewire::test(Dashboard::class)->assertOk();

        // Fila 1: Stats
        Livewire::test(StatsOverview::class)
            ->assertOk()
            ->assertSee('Ventas de hoy')
            ->assertSee('Unidades vendidas hoy')
            ->assertSee('Variantes activas')
            ->assertSee('Alertas de stock');

        // Fila 2: Stock por talla
        Livewire::test(StockBySizeWidget::class)
            ->assertOk()
            ->assertSee('Stock por talla');

        // Fila 3: Caja y Traspasos
        Livewire::test(ShopCashWidget::class)->assertOk()->assertSee('Caja de la tienda');
        Livewire::test(InTransitTransfersWidget::class)->assertOk()->assertSee('Traspasos en ruta');

        // Fila 4: Gráfico y Top 5
        Livewire::test(SalesBreakdownChart::class)->assertOk();
        Livewire::test(TopModelsWidget::class)->assertOk()->assertSee('Top 5 modelos');

        // Fila 5: Indicadores financieros (Admin ve las 3 tarjetas)
        Livewire::test(AdminFinancialStats::class)
            ->assertOk()
            ->assertSee('Por cobrar vencido')
            ->assertSee('Aprobaciones pendientes')
            ->assertSee('Margen del mes');
    }

    public function test_vendedor_ve_panel_de_su_tienda_con_caja_y_stock(): void
    {
        $vendedor = User::where('email', 'vendedor@demo.test')->firstOrFail();
        $this->actingAs($vendedor);

        CashRegister::create([
            'user_id' => $vendedor->id,
            'branch_id' => 1,
            'status' => 'abierta',
            'opened_at' => now(),
            'initial_amount' => 150.00,
        ]);

        Livewire::test(StatsOverview::class)
            ->assertOk()
            ->assertSee('Ventas de hoy')
            ->assertSee('Unidades vendidas');

        Livewire::test(StockBySizeWidget::class)->assertOk();
        Livewire::test(ShopCashWidget::class)->assertOk()->assertSee('Abierta');
        Livewire::test(InTransitTransfersWidget::class)->assertOk();

        // Vendedor no ve métricas gerenciales ni costos
        $this->assertFalse(SalesBreakdownChart::canView());
        $this->assertFalse(TopModelsWidget::canView());
        $this->assertFalse(AdminFinancialStats::canView());
    }

    public function test_almacenero_no_ve_ventas_ni_caja_en_tarjetas(): void
    {
        $almacenero = User::where('email', 'almacenero@demo.test')->firstOrFail();
        $this->actingAs($almacenero);

        Livewire::test(StatsOverview::class)
            ->assertOk()
            ->assertDontSee('Ventas de hoy')
            ->assertDontSee('Unidades vendidas hoy')
            ->assertSee('Variantes activas')
            ->assertSee('Alertas de stock');

        // Almacenero no ve la caja de dinero
        $this->assertFalse(ShopCashWidget::canView());

        // Sí ve traspasos y stock
        Livewire::test(StockBySizeWidget::class)->assertOk();
        Livewire::test(InTransitTransfersWidget::class)->assertOk()->assertSee('Traspasos en ruta');

        // No ve costos ni finanzas
        $this->assertFalse(AdminFinancialStats::canView());
    }

    public function test_contador_ve_margen_pero_no_cobranzas(): void
    {
        $contador = User::where('email', 'contador@demo.test')->firstOrFail();
        $this->actingAs($contador);

        $this->assertTrue(AdminFinancialStats::canView());
        $this->assertTrue(SalesBreakdownChart::canView());
        $this->assertTrue(TopModelsWidget::canView());

        Livewire::test(AdminFinancialStats::class)
            ->assertOk()
            ->assertSee('Margen del mes')
            ->assertDontSee('Por cobrar vencido');
    }

    public function test_dashboard_metrics_calcula_ventas_netas_unidades_y_margen(): void
    {
        $admin = User::where('email', 'administrador@demo.test')->firstOrFail();
        $this->actingAs($admin);

        $customer = Customer::firstOrFail();
        $product = Product::where('name', 'Polo básico algodón pima')->firstOrFail();
        $variant = $product->variants()->firstOrFail();

        // 1. Venta minorista: 2 polos @ 59.90 = 119.80 (subtotal = 101.53, igv = 18.27)
        $subtotal = 101.53;
        $igv = 18.27;
        $total = 119.80;

        $sale = Sale::create([
            'user_id' => $admin->id,
            'customer_id' => $customer->id,
            'branch_id' => 1,
            'warehouse_id' => 1,
            'document_type' => '03',
            'serie' => 'B001',
            'correlative' => 501,
            'issue_date' => now(),
            'subtotal' => $subtotal,
            'igv' => $igv,
            'total' => $total,
            'payment_condition' => 'contado',
            'payment_method' => 'efectivo',
            'sunat_status' => 'aceptado',
        ]);

        $item = $sale->items()->create([
            'product_variant_id' => $variant->id,
            'product_name' => $product->name,
            'quantity' => 2,
            'price' => 59.90,
            'igv' => $igv,
            'total' => $total,
            'price_type' => 'retail',
        ]);

        StockService::move($variant->id, 1, -2, 'sale', $sale, 'Venta B001-501', 25.00, 'B001-501');

        // Verificar métricas
        $todaySales = DashboardMetrics::todaySales(1);
        $this->assertEquals(119.80, $todaySales);

        $todayUnits = DashboardMetrics::todayUnitsSold(1);
        $this->assertEquals(2, $todayUnits);

        // Devolución de 1 polo
        \App\Services\SaleExchangeService::register(
            $sale,
            [$item->id => 1],
            'devolucion',
            'Talla incorrecta'
        );

        // Ventas y unidades deben ser netas
        $todaySalesAfter = DashboardMetrics::todaySales(1);
        $this->assertEquals(59.90, $todaySalesAfter);

        $todayUnitsAfter = DashboardMetrics::todayUnitsSold(1);
        $this->assertEquals(1, $todayUnitsAfter);

        // Margen del mes
        $margin = DashboardMetrics::monthMargin(1);
        $this->assertGreaterThan(0, $margin['margin_amount']);
        $this->assertGreaterThan(0, $margin['margin_percent']);

        // Últimas 5 ventas widget visible
        $this->actingAs($admin);
        $this->assertTrue(LatestSalesWidget::canView());
        Livewire::test(LatestSalesWidget::class)->assertOk()->assertSee('B001-501');
    }
}
