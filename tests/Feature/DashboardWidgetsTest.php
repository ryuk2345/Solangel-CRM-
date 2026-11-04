<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\LatestSalesWidget;
use App\Filament\Widgets\StatsOverview;
use App\Filament\Widgets\StockBySizeWidget;
use App\Models\CashRegister;
use App\Models\User;
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
        Livewire::test(StatsOverview::class)->assertOk()->assertSee('Ventas de hoy')->assertSee('Unidades vendidas');
        Livewire::test(StockBySizeWidget::class)->assertOk()->assertSee('Stock por talla');
        Livewire::test(LatestSalesWidget::class)->assertOk();
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
            ->assertSee('Caja diaria');

        Livewire::test(StockBySizeWidget::class)->assertOk();
    }

    public function test_almacenero_no_ve_ventas_ni_caja_en_tarjetas(): void
    {
        $almacenero = User::where('email', 'almacenero@demo.test')->firstOrFail();
        $this->actingAs($almacenero);

        Livewire::test(StatsOverview::class)
            ->assertOk()
            ->assertDontSee('Ventas de hoy')
            ->assertDontSee('Caja diaria')
            ->assertSee('Traspasos en ruta')
            ->assertSee('Alertas de stock');

        Livewire::test(StockBySizeWidget::class)->assertOk();
    }
}
