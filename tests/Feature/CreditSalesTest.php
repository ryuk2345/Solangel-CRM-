<?php

namespace Tests\Feature;

use App\Filament\Resources\SaleResource\Pages\CreateSale;
use App\Models\CashRegister;
use App\Models\Customer;
use App\Models\Inventory;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\CreditService;
use App\Services\SunatService;
use Database\Seeders\DemoDataSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** Ventas a crédito para mayoristas (decisión 8). */
class CreditSalesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Warehouse $warehouse;
    private User $vendedor;
    private Customer $mayorista;
    private CashRegister $caja;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('DEMO_PASSWORD=secret-demo');
        $this->seed(DemoDataSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->warehouse = Warehouse::where('is_default', true)->firstOrFail();
        $this->vendedor = User::where('email', 'vendedor@demo.test')->firstOrFail();
        $this->mayorista = Customer::where('customer_type', 'mayorista')->firstOrFail();
        $this->actingAs($this->vendedor);
        $this->caja = CashRegister::create(['user_id' => $this->vendedor->id, 'branch_id' => 1, 'status' => 'abierta', 'opened_at' => now()]);
    }

    private function items(int $count = 2, int $qty = 1): array
    {
        return Inventory::where('warehouse_id', $this->warehouse->id)->where('stock', '>=', 10)
            ->whereHas('productVariant.product', fn ($p) => $p->where('is_service', false))
            ->with('productVariant.product')->limit($count)->get()
            ->mapWithKeys(fn ($inv, $i) => ["l{$i}" => [
                'product_variant_id' => $inv->product_variant_id, 'quantity' => $qty, 'price' => 1,
                'product_name' => $inv->productVariant->display_name,
            ]])->all();
    }

    private function sell(Customer $customer, array $items, string $serie, string $condition, array $payments): void
    {
        $page = Livewire::test(CreateSale::class);
        $page->set('data', array_merge($page->get('data'), [
            'customer_id' => $customer->id, 'branch_id' => 1, 'warehouse_id' => $this->warehouse->id,
            'serie' => $serie, 'issue_date' => now()->format('Y-m-d H:i:s'), 'seller_id' => $this->vendedor->id,
            'items' => $items, 'payment_condition' => $condition, 'payments' => $payments,
        ]))->call('create');
    }

    public function test_venta_a_credito_con_adelanto_y_abonos(): void
    {
        $this->sell($this->mayorista, $this->items(), 'NV01', 'credito', [['payment_method' => 'efectivo', 'amount' => 20]]);

        $sale = Sale::latest('id')->firstOrFail();
        $this->assertSame('credito', $sale->payment_condition);
        $this->assertEquals(round((float) $sale->total - 20, 2), (float) $sale->credit_balance);
        $this->assertSame(now()->addDays(30)->toDateString(), $sale->due_date->toDateString());
        $this->assertEquals(20, (float) $sale->payments()->sum('amount'), 'Solo el adelanto queda como pago');
        $this->assertEquals(20, (float) $this->caja->fresh()->cash_sales, 'A caja entra solo el adelanto en efectivo');
        $this->assertEquals((float) $sale->credit_balance, $this->mayorista->outstandingBalance());

        // Abono en efectivo: entra a caja como ingreso
        CreditService::registerPayment($sale, 'efectivo', 30, null);
        $this->assertEquals(round((float) $sale->total - 50, 2), (float) $sale->fresh()->credit_balance);
        $this->assertEquals(30, (float) $this->caja->fresh()->cash_incomes);
        $this->assertDatabaseHas('cash_register_movements', ['type' => 'ingreso', 'amount' => 30]);

        // Abono por Yape: no toca la caja de efectivo
        CreditService::registerPayment($sale, 'yape', (float) $sale->fresh()->credit_balance, 'OP-123');
        $this->assertEquals(0, (float) $sale->fresh()->credit_balance);
        $this->assertEquals(30, (float) $this->caja->fresh()->cash_incomes);

        $this->expectException(ValidationException::class);
        CreditService::registerPayment($sale->fresh(), 'efectivo', 1, null); // ya no debe nada
    }

    public function test_credito_solo_para_mayoristas_habilitados_y_sin_boleta(): void
    {
        $minorista = Customer::where('customer_type', 'minorista')->firstOrFail();
        $this->sell($minorista, $this->items(), 'NV01', 'credito', []);
        $this->assertSame(0, Sale::count(), 'Minorista no compra a crédito');

        $this->sell($this->mayorista, $this->items(), 'B001', 'credito', []);
        $this->assertSame(0, Sale::count(), 'Boleta a crédito no se permite');
    }

    public function test_limite_de_credito_solo_lo_excede_el_administrador(): void
    {
        $this->mayorista->update(['credit_limit' => 50]);
        $this->sell($this->mayorista, $this->items(2, 2), 'NV01', 'credito', []);
        $this->assertSame(0, Sale::count(), 'El vendedor no puede pasar el límite');

        $admin = User::where('email', 'administrador@demo.test')->firstOrFail();
        $due = CreditService::assertCanSellOnCredit($this->mayorista, 500, $admin);
        $this->assertSame(now()->addDays(30)->toDateString(), $due->toDateString());
    }

    public function test_factura_a_credito_lleva_forma_de_pago_credito_con_cuota(): void
    {
        $this->app->instance(SunatService::class, new class extends SunatService {
            public function __construct() {}
            public function enviarBoleta(\App\Models\Sale $venta, bool $isRetry = false) { return ['success' => true]; }
        });
        $this->sell($this->mayorista, $this->items(), 'F001', 'credito', [['payment_method' => 'transferencia', 'amount' => 10]]);
        $sale = Sale::latest('id')->firstOrFail();

        $service = new SunatService();
        $method = new \ReflectionMethod($service, 'formaPago');
        $forma = $method->invoke($service, $sale, '01');
        $cuotas = (new \ReflectionProperty($service, 'cuotasCredito'))->getValue($service);

        $this->assertInstanceOf(\Greenter\Model\Sale\FormaPagos\FormaPagoCredito::class, $forma);
        $this->assertEquals((float) $sale->total - 10, $forma->getMonto());
        $this->assertCount(1, $cuotas);
        $this->assertSame($sale->due_date->toDateString(), $cuotas[0]->getFechaPago()->format('Y-m-d'));

        // Contado sigue igual
        $sale->update(['payment_condition' => 'contado']);
        $this->assertInstanceOf(\Greenter\Model\Sale\FormaPagos\FormaPagoContado::class, $method->invoke($service, $sale, '01'));
    }
}
