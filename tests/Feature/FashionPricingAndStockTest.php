<?php

namespace Tests\Feature;

use App\Filament\Resources\SaleResource\Pages\CreateSale;
use App\Models\CashRegister;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\Serie;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WholesalePriceRequest;
use App\Services\PricingService;
use App\Services\StockService;
use App\Services\WholesaleApprovalService;
use Database\Seeders\DemoDataSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Reglas del negocio de la tienda de ropa (PLAN_MODA §5 y §8):
 * precio minorista/mayorista, aprobación con PIN, precio manual solo del administrador,
 * delivery, y stock sin negativos con kardex.
 */
class FashionPricingAndStockTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Warehouse $warehouse;
    private User $vendedor;
    private User $admin;
    private Customer $minorista;
    private Customer $mayorista;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('DEMO_PASSWORD=secret-demo');
        $this->seed(DemoDataSeeder::class);

        $this->warehouse = Warehouse::where('is_default', true)->firstOrFail();
        $this->vendedor = User::where('email', 'vendedor@demo.test')->firstOrFail();
        $this->admin = User::where('email', 'administrador@demo.test')->firstOrFail();
        $this->minorista = Customer::where('customer_type', 'minorista')->firstOrFail();
        $this->mayorista = Customer::where('customer_type', 'mayorista')->firstOrFail();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /** Una variante con stock suficiente en el almacén principal. */
    private function variant(int $minStock = 5, int $skip = 0): ProductVariant
    {
        $inv = Inventory::where('warehouse_id', $this->warehouse->id)->where('stock', '>=', $minStock)
            ->whereHas('productVariant.product', fn ($p) => $p->where('is_service', false))
            ->orderBy('id')->skip($skip)->firstOrFail();

        return $inv->productVariant()->with('product')->first();
    }

    private function delivery(): ProductVariant
    {
        return ProductVariant::where('sku', 'DELIVERY')->firstOrFail();
    }

    public function test_una_prenda_va_a_precio_minorista(): void
    {
        $v = $this->variant();
        $r = PricingService::apply([['product_variant_id' => $v->id, 'quantity' => 1]], $this->minorista->id, 1);

        $this->assertSame('minorista', $r['mode']);
        $this->assertEquals($v->retail_price, $r['lines'][0]['price']);
    }

    public function test_dos_prendas_de_cualquier_modelo_van_a_precio_mayorista(): void
    {
        $a = $this->variant();
        $b = ProductVariant::where('product_id', '!=', $a->product_id)
            ->whereHas('product', fn ($p) => $p->where('is_service', false))->firstOrFail();

        $r = PricingService::apply([
            ['product_variant_id' => $a->id, 'quantity' => 1],
            ['product_variant_id' => $b->id, 'quantity' => 1],
        ], $this->minorista->id, 1);

        $this->assertSame('mayorista', $r['mode']);
        $this->assertSame('quantity', $r['reason']);
        $this->assertEquals($a->effective_wholesale_price, $r['lines'][0]['price']);
        $this->assertEquals($b->effective_wholesale_price, $r['lines'][1]['price']);
    }

    public function test_el_delivery_no_cuenta_como_prenda(): void
    {
        $r = PricingService::apply([
            ['product_variant_id' => $this->variant()->id, 'quantity' => 1],
            ['product_variant_id' => $this->delivery()->id, 'quantity' => 1, 'price' => 10],
        ], $this->minorista->id, 1);

        $this->assertSame('minorista', $r['mode']);
        $this->assertSame(1, $r['garments']);
        $this->assertSame('service', $r['lines'][1]['price_type']);
    }

    public function test_el_minimo_de_prendas_es_configurable(): void
    {
        \App\Models\CompanySetting::query()->update(['wholesale_min_qty' => 3]);
        $v = $this->variant();

        $r = PricingService::apply([['product_variant_id' => $v->id, 'quantity' => 2]], $this->minorista->id, 1);

        $this->assertSame('minorista', $r['mode']);
    }

    public function test_el_vendedor_no_puede_forzar_un_precio(): void
    {
        $v = $this->variant();
        $r = PricingService::apply(
            [['product_variant_id' => $v->id, 'quantity' => 1, 'price' => 1.00, 'price_reason' => 'manual']],
            $this->minorista->id, 1, null, $this->vendedor,
        );

        $this->assertEquals($v->retail_price, $r['lines'][0]['price']);
    }

    public function test_el_administrador_si_puede_poner_precio_a_mano(): void
    {
        $v = $this->variant();
        $r = PricingService::apply(
            [['product_variant_id' => $v->id, 'quantity' => 1, 'price' => 40.00, 'price_reason' => 'manual']],
            $this->minorista->id, 1, null, $this->admin,
        );

        $this->assertEquals(40.00, $r['lines'][0]['price']);
        $this->assertSame('manual', $r['lines'][0]['price_reason']);
    }

    public function test_una_prenda_a_mayorista_solo_con_aprobacion_del_administrador_y_su_pin(): void
    {
        $v = $this->variant();
        $items = [['product_variant_id' => $v->id, 'quantity' => 1]];

        // Un cliente minorista no puede pedirla
        $this->expectExceptionMessage('cliente marcado como mayorista');
        WholesaleApprovalService::request($this->minorista->id, 1, $items, $this->vendedor);
    }

    public function test_flujo_de_aprobacion_con_aviso_y_pin(): void
    {
        $v = $this->variant();
        $items = [['product_variant_id' => $v->id, 'quantity' => 1]];

        $request = WholesaleApprovalService::request($this->mayorista->id, 1, $items, $this->vendedor);
        $this->assertSame('pendiente', $request->status);
        $this->assertSame(1, $this->admin->notifications()->count(), 'El administrador recibe el aviso en el sistema');

        // Pendiente: todavía minorista
        $r = PricingService::apply($items, $this->mayorista->id, 1, $request->id);
        $this->assertSame('minorista', $r['mode']);

        // PIN incorrecto
        try {
            WholesaleApprovalService::approve($request, $this->admin, '9999');
            $this->fail('Debió rechazar el PIN');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('PIN incorrecto', collect($e->errors())->flatten()->first());
        }

        // El vendedor no puede aprobar
        try {
            WholesaleApprovalService::approve($request->fresh(), $this->vendedor, '1234');
            $this->fail('El vendedor no debe poder aprobar');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no puede aprobar', collect($e->errors())->flatten()->first());
        }

        WholesaleApprovalService::approve($request->fresh(), $this->admin, '1234');
        $this->assertSame(1, $this->vendedor->notifications()->count(), 'El vendedor recibe el aviso de aprobación');

        $r = PricingService::apply($items, $this->mayorista->id, 1, $request->id);
        $this->assertSame('mayorista', $r['mode']);
        $this->assertSame('authorized', $r['reason']);

        // No sirve para otro cliente
        $r = PricingService::apply($items, $this->minorista->id, 1, $request->id);
        $this->assertSame('minorista', $r['mode']);
    }

    public function test_no_se_permite_stock_negativo_y_todo_queda_en_el_kardex(): void
    {
        $v = $this->variant();
        $before = StockService::available($v->id, $this->warehouse->id);

        StockService::move($v->id, $this->warehouse->id, -2, 'adjustment', null, 'prueba');
        $this->assertSame($before - 2, StockService::available($v->id, $this->warehouse->id));
        $this->assertDatabaseHas('stock_movements', ['product_variant_id' => $v->id, 'quantity' => -2, 'balance_after' => $before - 2]);

        $this->expectException(ValidationException::class);
        StockService::move($v->id, $this->warehouse->id, -($before + 100), 'adjustment');
    }

    private function openRegister(User $user): CashRegister
    {
        return CashRegister::create([
            'user_id' => $user->id, 'branch_id' => 1, 'status' => 'abierta',
            'opened_at' => now(), 'initial_amount' => 0,
        ]);
    }

    private function saleForm(array $items, Customer $customer, string $serie = 'NV01'): array
    {
        return [
            'customer_id' => $customer->id,
            'branch_id' => 1,
            'warehouse_id' => $this->warehouse->id,
            'serie' => $serie,
            'issue_date' => now()->format('Y-m-d H:i:s'),
            'seller_id' => $this->vendedor->id,
            'items' => $items,
            'payments' => [['payment_method' => 'efectivo', 'amount' => 0]],
        ];
    }

    public function test_venta_completa_recalcula_precios_en_el_servidor_y_descuenta_stock(): void
    {
        $this->actingAs($this->vendedor);
        $register = $this->openRegister($this->vendedor);
        $a = $this->variant(5);
        $b = $this->variant(5, 1);
        $stockA = StockService::available($a->id, $this->warehouse->id);

        // El formulario manda precios falsos: el servidor debe ignorarlos
        $items = [
            'x1' => ['product_variant_id' => $a->id, 'quantity' => 1, 'price' => 1, 'total' => 1, 'product_name' => $a->display_name],
            'x2' => ['product_variant_id' => $b->id, 'quantity' => 1, 'price' => 1, 'total' => 1, 'product_name' => $b->display_name],
        ];

        Livewire::test(CreateSale::class)
            ->set('data', array_merge(Livewire::test(CreateSale::class)->get('data'), $this->saleForm($items, $this->minorista)))
            ->call('create')
            ->assertHasNoErrors();

        $this->assertSame(1, Sale::count(), 'Notificaciones: ' . json_encode(session()->get('filament.notifications')));
        $sale = Sale::latest('id')->firstOrFail();
        $expected = round($a->effective_wholesale_price + $b->effective_wholesale_price, 2);

        $this->assertSame('mayorista', $sale->price_mode);
        $this->assertEquals($expected, (float) $sale->total);
        $this->assertEquals($a->effective_wholesale_price, (float) $sale->items()->where('product_variant_id', $a->id)->value('price'));
        $this->assertSame($stockA - 1, StockService::available($a->id, $this->warehouse->id));
        $this->assertSame(2, StockMovement::where('reference_type', $sale->getMorphClass())->where('reference_id', $sale->id)->where('type', 'sale')->count());
        $this->assertEquals($expected, (float) $register->fresh()->cash_sales, 'La caja suma el efectivo real');
        $this->assertEquals($expected, (float) $sale->payments()->sum('amount'));
    }

    public function test_venta_sin_stock_no_se_guarda_y_no_consume_correlativo(): void
    {
        $this->actingAs($this->vendedor);
        $this->openRegister($this->vendedor);
        $v = $this->variant();
        $stock = StockService::available($v->id, $this->warehouse->id);
        $corr = Serie::where('serie', 'NV01')->value('current_number');

        $items = ['x1' => ['product_variant_id' => $v->id, 'quantity' => $stock + 1, 'price' => 1, 'product_name' => $v->display_name]];

        Livewire::test(CreateSale::class)
            ->set('data', array_merge(Livewire::test(CreateSale::class)->get('data'), $this->saleForm($items, $this->minorista)))
            ->call('create');

        $this->assertSame(0, Sale::count());
        $this->assertSame($stock, StockService::available($v->id, $this->warehouse->id));
        $this->assertSame($corr, Serie::where('serie', 'NV01')->value('current_number'));
    }

    public function test_delivery_solo_si_alguna_prenda_lo_permite(): void
    {
        $this->actingAs($this->vendedor);
        $this->openRegister($this->vendedor);
        // La casaca no permite delivery en los datos demo
        $casaca = ProductVariant::whereHas('product', fn ($p) => $p->where('name', 'like', 'Casaca%'))
            ->whereHas('inventories', fn ($i) => $i->where('stock', '>', 0))->firstOrFail();

        $items = [
            'x1' => ['product_variant_id' => $casaca->id, 'quantity' => 1, 'price' => 1, 'product_name' => $casaca->display_name],
            'x2' => ['product_variant_id' => $this->delivery()->id, 'quantity' => 1, 'price' => 10, 'product_name' => 'Delivery'],
        ];

        Livewire::test(CreateSale::class)
            ->set('data', array_merge(Livewire::test(CreateSale::class)->get('data'), $this->saleForm($items, $this->minorista)))
            ->call('create');

        $this->assertSame(0, Sale::count());
    }

    public function test_aprobacion_mayorista_se_usa_en_una_sola_venta(): void
    {
        $this->actingAs($this->vendedor);
        $this->openRegister($this->vendedor);
        $v = $this->variant();
        $items = ['x1' => ['product_variant_id' => $v->id, 'quantity' => 1, 'price' => 1, 'product_name' => $v->display_name]];

        $request = WholesaleApprovalService::approveOnSpot($this->mayorista->id, 1, $items, $this->vendedor, $this->admin, '1234');

        $data = $this->saleForm($items, $this->mayorista);
        $data['wholesale_request_id'] = $request->id;

        Livewire::test(CreateSale::class)
            ->set('data', array_merge(Livewire::test(CreateSale::class)->get('data'), $data))
            ->call('create')
            ->assertHasNoErrors();

        $sale = Sale::latest('id')->firstOrFail();
        $this->assertSame('mayorista', $sale->price_mode);
        $this->assertSame('authorized', $sale->items()->value('price_reason'));
        $this->assertSame('usada', $request->fresh()->status);
        $this->assertSame($sale->id, $request->fresh()->sale_id);
    }

    public function test_punto_de_venta_escanear_y_elegir_por_modelo_agregan_prendas_con_precio(): void
    {
        $this->actingAs($this->vendedor);
        $this->openRegister($this->vendedor);
        $a = $this->variant(5);
        $b = $this->variant(5, 1);

        // La sección tiene key 'detalle' (sin prefijo data.), así que se llama a la acción como lo hace el navegador
        $act = fn ($c, string $name, array $data) => $c
            ->call('mountFormComponentAction', 'detalle', $name)
            ->set('mountedFormComponentActionsData.0', $data)
            ->call('callMountedFormComponentAction');

        $component = Livewire::test(CreateSale::class)
            ->set('data.customer_id', $this->minorista->id)
            ->set('data.items', []);
        $act($component, 'scan', ['code' => $a->barcode, 'quantity' => 1]);

        $items = collect($component->get('data.items'));
        $this->assertCount(1, $items);
        $this->assertEquals($a->retail_price, $items->first()['price'], 'Con 1 prenda: minorista');
        $this->assertSame($a->display_name, $items->first()['product_name']);

        $act($component, 'pick_model', [
            'product_id' => $b->product_id, 'color' => $b->color_name, 'variant_id' => $b->id, 'quantity' => 1,
        ]);

        $items = collect($component->get('data.items'));
        $this->assertCount($a->id === $b->id ? 1 : 2, $items);
        $this->assertTrue($items->every(fn ($i) => $i['price_type'] === 'wholesale'), 'Con 2 prendas: todo a mayorista');

        // Escanear más de lo que hay en stock no agrega
        $act($component, 'scan', ['code' => $a->barcode, 'quantity' => 9999]);
        $this->assertSame($items->sum('quantity'), collect($component->get('data.items'))->sum('quantity'));
    }
}
