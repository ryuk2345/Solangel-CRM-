<?php

namespace Tests\Feature;

use App\Filament\Pages\InventoryReport;
use App\Filament\Pages\SalesReport;
use App\Filament\Resources\SaleResource\Pages\CreateSale;
use App\Models\CashRegister;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\ReportService;
use App\Services\SaleExchangeService;
use App\Services\StockService;
use Database\Seeders\DemoDataSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReportsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Warehouse $warehouse;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('DEMO_PASSWORD=secret-demo');
        $this->seed(DemoDataSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->warehouse = Warehouse::where('is_default', true)->firstOrFail();
        $this->admin = User::where('email', 'administrador@demo.test')->firstOrFail();
        $this->actingAs($this->admin);
        CashRegister::create(['user_id' => $this->admin->id, 'branch_id' => 1, 'status' => 'abierta', 'opened_at' => now()]);
    }

    private function sell(array $variantQty): Sale
    {
        $items = [];
        foreach ($variantQty as $i => [$v, $q]) {
            $items["l{$i}"] = ['product_variant_id' => $v->id, 'quantity' => $q, 'price' => 1, 'product_name' => $v->display_name];
        }
        $this->travel(10)->seconds();
        $page = Livewire::test(CreateSale::class);
        $page->set('data', array_merge($page->get('data'), [
            'customer_id' => Customer::where('customer_type', 'minorista')->value('id'), 'branch_id' => 1,
            'warehouse_id' => $this->warehouse->id, 'serie' => 'NV01', 'issue_date' => now()->format('Y-m-d H:i:s'),
            'seller_id' => $this->admin->id, 'items' => $items, 'payments' => [['payment_method' => 'efectivo', 'amount' => 0]],
        ]))->call('create')->assertHasNoErrors();

        return Sale::latest('id')->firstOrFail();
    }

    public function test_ventas_netas_por_talla_y_canal(): void
    {
        $v = Inventory::where('warehouse_id', $this->warehouse->id)->where('stock', '>=', 10)
            ->whereHas('productVariant', fn ($q) => $q->whereNotNull('size_id'))->firstOrFail()->productVariant;

        $mayorista = $this->sell([[$v, 3]]);   // 3 prendas: mayorista
        $this->sell([[$v, 1]]);                // 1 prenda: minorista
        SaleExchangeService::register($mayorista, [$mayorista->items()->value('id') => 1], 'cambio', 'Talla incorrecta');

        $bySize = ReportService::salesBy('size', now()->subDay(), now()->addDay());
        $row = $bySize->firstWhere('label', $v->size);
        $this->assertSame(3, $row['units'], '3 + 1 vendidas − 1 devuelta');

        $byChannel = ReportService::salesBy('channel', now()->subDay(), now()->addDay())->keyBy('label');
        $this->assertSame(2, $byChannel['Mayorista']['units']);
        $this->assertSame(1, $byChannel['Minorista']['units']);
        $this->assertEquals(round($v->effective_wholesale_price * 2, 2), $byChannel['Mayorista']['amount']);

        Livewire::test(SalesReport::class)->assertOk()->assertSee($v->size);
        $this->assertNotNull(Livewire::test(SalesReport::class)->call('export', 'size'));
    }

    public function test_curva_rota_y_stock_sin_movimiento(): void
    {
        $inv = Inventory::where('warehouse_id', $this->warehouse->id)->where('stock', '>', 0)
            ->whereHas('productVariant', fn ($q) => $q->whereNotNull('size_id'))->with('productVariant.product')->firstOrFail();
        StockService::setTo($inv->product_variant_id, $this->warehouse->id, 0, 'prueba');

        $broken = ReportService::brokenCurves($this->warehouse->id)
            ->first(fn ($r) => $r['model'] === $inv->productVariant->product->name && $r['color'] === $inv->productVariant->color_name);
        $this->assertNotNull($broken);
        $this->assertContains($inv->productVariant->size, $broken['missing']);

        $this->assertTrue(ReportService::deadStock(90)->isEmpty(), 'El stock demo entró hoy');
        $this->travel(100)->days();
        $this->assertTrue(ReportService::deadStock(90)->isNotEmpty(), 'Sin ventas en 100 días');

        Livewire::test(InventoryReport::class)->assertOk()->assertSee('Curva rota');
    }
}
