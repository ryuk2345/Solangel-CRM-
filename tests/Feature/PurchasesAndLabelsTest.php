<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseResource\Pages\CreatePurchase;
use App\Models\Color;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\SizeCurve;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PurchaseService;
use App\Services\StockService;
use Database\Seeders\DemoDataSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class PurchasesAndLabelsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private Warehouse $warehouse;
    private Supplier $supplier;
    private User $almacenero;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('DEMO_PASSWORD=secret-demo');
        $this->seed(DemoDataSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->warehouse = Warehouse::where('is_default', true)->firstOrFail();
        $this->supplier = Supplier::create(['document_type' => '6', 'document_number' => '20111111111', 'name' => 'Textil Gamarra SAC']);
        $this->almacenero = User::where('email', 'almacenero@demo.test')->firstOrFail();
    }

    public function test_compra_por_curva_crea_las_tallas_y_suma_stock_por_kardex(): void
    {
        $this->actingAs($this->almacenero);
        $product = Product::where('name', 'like', 'Casaca%')->firstOrFail();
        $curve = SizeCurve::where('name', 'Letras S–XL')->firstOrFail();
        $sizes = $curve->sizes->pluck('id', 'name');
        $negro = Color::where('code', 'NEG')->firstOrFail();
        $rojo = Color::where('code', 'ROJ')->firstOrFail(); // la casaca no tenía rojo: se crean sus variantes

        $lines = PurchaseService::expandCurve($product,
            [$negro->id => 2, $rojo->id => 1],
            [$sizes['S'] => 1, $sizes['M'] => 2, $sizes['L'] => 2, $sizes['XL'] => 1],
            68.00,
        );

        $this->assertCount(8, $lines);
        $this->assertSame(18, collect($lines)->sum('quantity')); // (1+2+2+1) × (2+1)

        $negroM = $product->variants()->where('color_id', $negro->id)->where('size_id', $sizes['M'])->firstOrFail();
        $before = StockService::available($negroM->id, $this->warehouse->id);

        $purchase = PurchaseService::register([
            'supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id,
            'document_type' => 'factura', 'document_number' => 'F001-123',
        ], $lines);

        $this->assertSame($before + 4, StockService::available($negroM->id, $this->warehouse->id));
        $this->assertEquals(18 * 68, (float) $purchase->total);
        $this->assertSame(8, $purchase->items()->count());
        $this->assertDatabaseHas('stock_movements', [
            'type' => 'purchase', 'product_variant_id' => $negroM->id, 'quantity' => 4,
            'reference_type' => $purchase->getMorphClass(), 'reference_id' => $purchase->id,
        ]);
        $this->assertTrue($product->variants()->where('color_id', $rojo->id)->exists(), 'Se crearon las variantes rojas');
    }

    public function test_anular_compra_saca_el_stock_y_no_se_puede_si_ya_se_vendio(): void
    {
        $this->actingAs($this->almacenero);
        $variant = Product::where('name', 'like', 'Polo%')->firstOrFail()->variants()->firstOrFail();
        $before = StockService::available($variant->id, $this->warehouse->id);

        $purchase = PurchaseService::register(['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id],
            [['product_variant_id' => $variant->id, 'quantity' => 5, 'unit_cost' => 20]]);
        PurchaseService::void($purchase, 'error de digitación');

        $this->assertSame('anulada', $purchase->fresh()->status);
        $this->assertSame($before, StockService::available($variant->id, $this->warehouse->id));

        // Si ya salió parte del stock, la anulación no deja el almacén en negativo
        $other = PurchaseService::register(['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id],
            [['product_variant_id' => $variant->id, 'quantity' => 3, 'unit_cost' => 20]]);
        StockService::setTo($variant->id, $this->warehouse->id, 1, 'conteo');

        $this->expectException(ValidationException::class);
        PurchaseService::void($other, 'prueba');
    }

    public function test_registrar_compra_desde_la_pantalla(): void
    {
        $this->actingAs($this->almacenero);
        $variant = Product::where('name', 'like', 'Jean%')->firstOrFail()->variants()->firstOrFail();
        $before = StockService::available($variant->id, $this->warehouse->id);

        Livewire::test(CreatePurchase::class)
            ->fillForm([
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'issue_date' => now()->toDateString(),
                'document_type' => 'guia',
            ])
            ->set('data.lines', ['a' => ['product_variant_id' => $variant->id, 'quantity' => 6, 'unit_cost' => 55]])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, Purchase::count());
        $this->assertSame($before + 6, StockService::available($variant->id, $this->warehouse->id));
    }

    public function test_etiquetas_en_pdf(): void
    {
        $this->actingAs($this->almacenero);
        $variant = Product::where('name', 'like', 'Polo%')->firstOrFail()->variants()->firstOrFail();
        $purchase = PurchaseService::register(['supplier_id' => $this->supplier->id, 'warehouse_id' => $this->warehouse->id],
            [['product_variant_id' => $variant->id, 'quantity' => 2, 'unit_cost' => 20]]);

        $this->get(route('labels.purchase', $purchase))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(route('labels.custom', ['items' => "{$variant->id}:3"]))->assertOk();

        // El vendedor no imprime etiquetas
        $this->actingAs(User::where('email', 'vendedor@demo.test')->first());
        $this->get(route('labels.purchase', $purchase))->assertForbidden();
    }
}
