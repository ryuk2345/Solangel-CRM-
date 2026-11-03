<?php

namespace Tests\Feature;

use App\Filament\Resources\SaleResource\Pages\CreateSale;
use App\Models\CashRegister;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\Serie;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\SaleAnulacionService;
use App\Services\SaleExchangeService;
use App\Services\StockService;
use App\Services\SunatService;
use Database\Seeders\DemoDataSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** Cambios de talla/color con nota de crédito parcial + venta nueva (decisión 7). */
class SizeExchangeTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Warehouse $warehouse;
    private User $vendedor;
    private User $admin;
    private Customer $cliente;
    private CashRegister $caja;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('DEMO_PASSWORD=secret-demo');
        $this->seed(DemoDataSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->warehouse = Warehouse::where('is_default', true)->firstOrFail();
        $this->vendedor = User::where('email', 'vendedor@demo.test')->firstOrFail();
        $this->admin = User::where('email', 'administrador@demo.test')->firstOrFail();
        $this->cliente = Customer::where('customer_type', 'minorista')->firstOrFail();
        $this->actingAs($this->vendedor);
        $this->caja = CashRegister::create(['user_id' => $this->vendedor->id, 'branch_id' => 1, 'status' => 'abierta', 'opened_at' => now()]);
    }

    /** Dos tallas del mismo modelo y color con stock. */
    private function twoSizes(): array
    {
        $variants = Inventory::where('warehouse_id', $this->warehouse->id)->where('stock', '>=', 5)
            ->with('productVariant.product')->get()->pluck('productVariant')
            ->filter(fn ($v) => !$v->product->is_service)
            ->groupBy(fn ($v) => $v->product_id . '|' . $v->color_name)
            ->first(fn ($g) => $g->count() >= 2);

        return [$variants->values()[0], $variants->values()[1]];
    }

    private function sell(array $lines, string $serie = 'NV01'): Sale
    {
        $items = [];
        foreach ($lines as $i => [$variant, $qty]) {
            $items["l{$i}"] = ['product_variant_id' => $variant->id, 'quantity' => $qty, 'price' => 1, 'product_name' => $variant->display_name];
        }
        $before = Sale::max('id');
        $page = Livewire::test(CreateSale::class);
        $page->set('data', array_merge($page->get('data'), [
            'customer_id' => $this->cliente->id, 'branch_id' => 1, 'warehouse_id' => $this->warehouse->id,
            'serie' => $serie, 'issue_date' => now()->format('Y-m-d H:i:s'), 'seller_id' => $this->vendedor->id,
            'items' => $items, 'payments' => [['payment_method' => 'efectivo', 'amount' => 0]],
        ]))->call('create')->assertHasNoErrors();

        return Sale::where('id', '>', (int) $before)->latest('id')->firstOrFail();
    }

    public function test_cambio_de_talla_completo_con_saldo_a_favor(): void
    {
        [$m, $l] = $this->twoSizes();
        $otro = ProductVariant::where('product_id', '!=', $m->product_id)
            ->whereHas('product', fn ($p) => $p->where('is_service', false))
            ->whereHas('inventories', fn ($i) => $i->where('warehouse_id', $this->warehouse->id)->where('stock', '>', 2))->firstOrFail();

        // Venta mayorista (2 prendas): talla M + otra prenda
        $venta = $this->sell([[$m, 1], [$otro, 1]]);
        $this->assertSame('mayorista', $venta->price_mode);
        $itemM = $venta->items()->where('product_variant_id', $m->id)->firstOrFail();
        $stockM = StockService::available($m->id, $this->warehouse->id);
        $cajaAntes = (float) $this->caja->fresh()->cash_sales;

        // El cliente devuelve la M para llevarse la L
        $cambio = SaleExchangeService::register($venta, [$itemM->id => 1], 'cambio', 'Talla incorrecta');

        $this->assertSame('interna', $cambio->sunat_status, 'Nota de venta: no lleva nota de crédito');
        $this->assertEquals((float) $itemM->total, (float) $cambio->total, 'Se devuelve al precio pagado');
        $this->assertSame($stockM + 1, StockService::available($m->id, $this->warehouse->id));
        $this->assertDatabaseHas('stock_movements', ['type' => 'exchange_in', 'product_variant_id' => $m->id, 'reference_id' => $cambio->id]);
        $this->assertSame([], array_intersect_key(SaleExchangeService::returnable($venta), [$itemM->id => 1]), 'Ya no se puede devolver de nuevo');

        // Venta nueva con el saldo a favor: 1 prenda, pero conserva precio mayorista por ser cambio de una venta mayorista
        $this->travel(10)->seconds(); // la protección contra doble clic bloquea otra venta del mismo usuario en 5 s
        $page = Livewire::withQueryParams(['cambio' => $cambio->id])->test(CreateSale::class);
        $this->assertEquals($cambio->id, $page->get('data.exchange_return_id'));
        $page->set('data.serie', 'NV01')
            ->set('data.items', ['n' => ['product_variant_id' => $l->id, 'quantity' => 1, 'price' => 1, 'product_name' => $l->display_name]])
            ->call('create')->assertHasNoErrors();

        $nueva = Sale::latest('id')->firstOrFail();
        $this->assertNotSame($venta->id, $nueva->id, 'No se creó la venta del cambio: ' . json_encode(session()->get('filament.notifications')));
        $this->assertSame($cambio->id, $nueva->exchange_return_id);
        $this->assertSame($nueva->id, $cambio->fresh()->new_sale_id);
        $this->assertSame('mayorista', $nueva->price_mode);
        $this->assertSame('exchange', $nueva->items()->value('price_reason'));
        $this->assertEquals((float) $nueva->total, (float) $nueva->payments()->sum('amount'));
        $credito = (float) $nueva->payments()->where('payment_method', 'nota_credito')->sum('amount');
        $this->assertEquals(min((float) $cambio->total, (float) $nueva->total), $credito);
        $this->assertEquals($cajaAntes + ((float) $nueva->total - $credito), (float) $this->caja->fresh()->cash_sales, 'En caja entra solo la diferencia');

        // El saldo no se puede usar dos veces
        $this->assertSame(0.0, SaleExchangeService::availableCredit($cambio->id));
    }

    public function test_no_se_devuelve_mas_de_lo_vendido_ni_dinero_sin_permiso(): void
    {
        [$m] = $this->twoSizes();
        $venta = $this->sell([[$m, 1]]);
        $item = $venta->items()->firstOrFail();

        try {
            SaleExchangeService::register($venta, [$item->id => 2], 'cambio', 'Otro');
            $this->fail('Debió impedir devolver 2 de 1');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('como máximo 1', collect($e->errors())->flatten()->first());
        }

        $this->expectExceptionMessage('Solo el administrador puede devolver dinero');
        SaleExchangeService::register($venta, [$item->id => 1], 'devolucion', 'Otro');
    }

    public function test_devolucion_de_dinero_del_administrador_sale_de_caja(): void
    {
        [$m] = $this->twoSizes();
        $venta = $this->sell([[$m, 1]]);
        $item = $venta->items()->firstOrFail();

        $this->actingAs($this->admin);
        CashRegister::create(['user_id' => $this->admin->id, 'branch_id' => 1, 'status' => 'abierta', 'opened_at' => now()]);
        $devolucion = SaleExchangeService::register($venta, [$item->id => 1], 'devolucion', 'Prenda con defecto');
        SaleExchangeService::refundCash($devolucion, (float) $devolucion->total);

        $this->assertEquals((float) $item->total, (float) $devolucion->fresh()->refunded_cash);
        $this->assertDatabaseHas('cash_register_movements', ['type' => 'egreso', 'amount' => $item->total]);
    }

    public function test_boleta_emite_nota_de_credito_parcial_y_bloquea_la_anulacion_total(): void
    {
        [$m, $l] = $this->twoSizes();

        // SUNAT simulado: acepta todo
        $fake = new class extends SunatService {
            public array $parciales = [];
            public function __construct() {}
            public function enviarBoleta(\App\Models\Sale $venta, bool $isRetry = false) { return ['success' => true]; }
            public function emitirNotaCreditoParcial(\App\Models\SaleReturn $return): array
            {
                $return->update(['credit_note_serie' => 'BC01', 'credit_note_correlative' => \App\Models\Serie::where('serie', 'BC01')->value('current_number')]);
                \App\Models\Serie::where('serie', 'BC01')->increment('current_number');
                $this->parciales[] = $return->id;

                return ['success' => true];
            }
        };
        $this->app->instance(SunatService::class, $fake);

        $venta = $this->sell([[$m, 2]], 'B001');
        $venta->update(['sunat_status' => 'aceptado']);
        $item = $venta->items()->firstOrFail();
        $ncAntes = Serie::where('serie', 'BC01')->value('current_number');

        $cambio = SaleExchangeService::register($venta, [$item->id => 1], 'cambio', 'Talla incorrecta');

        $this->assertSame('aceptado', $cambio->sunat_status);
        $this->assertSame('BC01-' . str_pad($ncAntes, 8, '0', STR_PAD_LEFT), $cambio->credit_note_number);
        $this->assertSame([$cambio->id], $fake->parciales);

        // Ya no se puede anular la venta completa (duplicaría el crédito)
        $res = SaleAnulacionService::anularVenta($venta->fresh(), ['motivo' => 'x'], $this->admin);
        $this->assertStringContainsString('cambios o devoluciones', $res['error'] ?? '');

        // Queda 1 prenda por devolver
        $this->assertSame([$item->id => 1], SaleExchangeService::returnable($venta));
    }

    public function test_boleta_no_aceptada_por_sunat_no_admite_cambios(): void
    {
        [$m] = $this->twoSizes();
        $this->app->instance(SunatService::class, new class extends SunatService {
            public function __construct() {}
            public function enviarBoleta(\App\Models\Sale $venta, bool $isRetry = false) { return ['error' => 'sin conexión']; }
        });
        $venta = $this->sell([[$m, 1]], 'B001');
        $this->assertSame('pendiente', $venta->fresh()->sunat_status);

        $this->expectExceptionMessage('todavía no está aceptada por SUNAT');
        SaleExchangeService::register($venta->fresh(), [$venta->items()->value('id') => 1], 'cambio', 'Otro');
    }

    public function test_xml_de_la_nota_de_credito_parcial_solo_incluye_lo_devuelto(): void
    {
        [$m, $l] = $this->twoSizes();
        $this->app->instance(SunatService::class, new class extends SunatService {
            public function __construct() {}
            public function enviarBoleta(\App\Models\Sale $venta, bool $isRetry = false) { return ['success' => true]; }
            public function emitirNotaCreditoParcial(\App\Models\SaleReturn $return): array { return ['error' => 'sin SUNAT en pruebas']; }
        });
        $venta = $this->sell([[$m, 2], [$l, 1]], 'B001');
        $venta->update(['sunat_status' => 'aceptado']);
        $itemM = $venta->items()->where('product_variant_id', $m->id)->firstOrFail();

        $cambio = SaleExchangeService::register($venta, [$itemM->id => 1], 'cambio', 'Talla incorrecta');
        $this->assertSame('pendiente', $cambio->sunat_status, 'Sin SUNAT queda pendiente para reenviar');

        $note = (new SunatService())->buildNotaCreditoParcial($cambio->fresh('items'), $venta->fresh('customer'), $venta->branch, 'BC01', 7);
        $xml = (new \Greenter\Xml\Builder\NoteBuilder())->build($note);

        $unit = round((float) $itemM->total / 2, 2);
        $this->assertStringContainsString('<cbc:ID>BC01-00000007</cbc:ID>', $xml);
        $this->assertStringContainsString('B001-' . str_pad($venta->correlative, 8, '0', STR_PAD_LEFT), $xml, 'Referencia a la boleta');
        $this->assertStringContainsString('<cbc:ResponseCode>07</cbc:ResponseCode>', $xml, 'Motivo 07: devolución por ítem');
        $this->assertSame(1, substr_count($xml, '<cac:CreditNoteLine>'), 'Solo la prenda devuelta');
        $this->assertStringContainsString('<cbc:PayableAmount currencyID="PEN">' . number_format($unit, 2, '.', '') . '</cbc:PayableAmount>', $xml);
        $this->assertStringContainsString(e($m->display_name), $xml);
    }
}
