<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\User;
use App\Models\WholesalePriceRequest;
use App\Services\PricingService;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QaPricingRulesTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('DEMO_PASSWORD=secret-demo');
        $this->seed(DemoDataSeeder::class);
    }

    /**
     * PREC-01: Venta con 1 sola prenda aplica automáticamente precio minorista.
     */
    public function test_prec_01_una_prenda_aplica_precio_minorista(): void
    {
        $polo = Product::where('name', 'Polo básico algodón pima')->firstOrFail();
        $variant = $polo->variants()->firstOrFail();

        $lines = [
            ['product_variant_id' => $variant->id, 'quantity' => 1],
        ];

        $result = PricingService::apply($lines, null, 1);

        $this->assertEquals('minorista', $result['mode']);
        $this->assertEquals(1, $result['garments']);
        $this->assertEquals($variant->retail_price, $result['lines'][0]['price']);
        $this->assertEquals('retail', $result['lines'][0]['price_type']);
    }

    /**
     * PREC-02: Venta respeta CompanySetting::wholesaleMinQty().
     * Si prendas >= umbral, aplica precio mayorista automáticamente.
     */
    public function test_prec_02_umbral_mayorista_respeta_configuracion_de_empresa(): void
    {
        $minQty = CompanySetting::wholesaleMinQty();
        $this->assertGreaterThan(0, $minQty);

        $polo = Product::where('name', 'Polo básico algodón pima')->firstOrFail();
        $variant = $polo->variants()->firstOrFail();

        // 1. Con menos prendas del umbral: minorista
        $linesUnder = [
            ['product_variant_id' => $variant->id, 'quantity' => $minQty - 1],
        ];
        if ($minQty > 1) {
            $resultUnder = PricingService::apply($linesUnder, null, 1);
            $this->assertEquals('minorista', $resultUnder['mode']);
            $this->assertEquals('retail', $resultUnder['lines'][0]['price_type']);
        }

        // 2. Con exactamente el umbral de prendas: mayorista
        $linesExact = [
            ['product_variant_id' => $variant->id, 'quantity' => $minQty],
        ];
        $resultExact = PricingService::apply($linesExact, null, 1);
        $this->assertEquals('mayorista', $resultExact['mode']);
        $this->assertEquals('wholesale', $resultExact['lines'][0]['price_type']);
        $this->assertEquals($variant->effective_wholesale_price, $resultExact['lines'][0]['price']);
    }

    /**
     * PREC-03: Delivery no cuenta como prenda para el umbral mayorista.
     */
    public function test_prec_03_delivery_no_suma_para_precio_mayorista(): void
    {
        $polo = Product::where('name', 'Polo básico algodón pima')->firstOrFail();
        $poloVar = $polo->variants()->firstOrFail();

        $delivery = Product::where('is_service', true)->firstOrFail();
        $deliveryVar = $delivery->variants()->firstOrFail();

        $lines = [
            ['product_variant_id' => $poloVar->id, 'quantity' => 1],
            ['product_variant_id' => $deliveryVar->id, 'quantity' => 1, 'price' => 15.00],
        ];

        $result = PricingService::apply($lines, null, 1);

        // Solo 1 prenda contabilizada, el delivery es service
        $this->assertEquals(1, $result['garments']);
        $this->assertEquals('minorista', $result['mode']);
        $this->assertEquals('retail', $result['lines'][0]['price_type']);
        $this->assertEquals('service', $result['lines'][1]['price_type']);
        $this->assertEquals(15.00, $result['lines'][1]['price']);
    }

    /**
     * PREC-04: Venta de 1 prenda a precio mayorista autorizada por Administrador con PIN.
     */
    public function test_prec_04_una_prenda_mayorista_con_solicitud_aprobada(): void
    {
        $customer = Customer::firstOrFail();
        $vendedor = User::where('email', 'vendedor@demo.test')->firstOrFail();
        $admin = User::where('email', 'administrador@demo.test')->firstOrFail();

        $polo = Product::where('name', 'Polo básico algodón pima')->firstOrFail();
        $variant = $polo->variants()->firstOrFail();

        $req = WholesalePriceRequest::create([
            'branch_id' => 1,
            'customer_id' => $customer->id,
            'requested_by' => $vendedor->id,
            'decided_by' => $admin->id,
            'decided_at' => now(),
            'items' => [['name' => $polo->name, 'quantity' => 1]],
            'status' => 'aprobada',
        ]);

        $lines = [
            ['product_variant_id' => $variant->id, 'quantity' => 1],
        ];

        $result = PricingService::apply($lines, $customer->id, 1, $req->id);

        $this->assertEquals('mayorista', $result['mode']);
        $this->assertEquals('authorized', $result['reason']);
        $this->assertEquals($variant->effective_wholesale_price, $result['lines'][0]['price']);
    }

    /**
     * PREC-05: Intento de reutilizar una autorización de precio mayorista en una segunda venta.
     */
    public function test_prec_05_autorizacion_mayorista_no_se_puede_reutilizar(): void
    {
        $customer = Customer::firstOrFail();
        $vendedor = User::where('email', 'vendedor@demo.test')->firstOrFail();
        $admin = User::where('email', 'administrador@demo.test')->firstOrFail();
        $polo = Product::where('name', 'Polo básico algodón pima')->firstOrFail();
        $variant = $polo->variants()->firstOrFail();

        $req = WholesalePriceRequest::create([
            'branch_id' => 1,
            'customer_id' => $customer->id,
            'requested_by' => $vendedor->id,
            'decided_by' => $admin->id,
            'decided_at' => now(),
            'items' => [['name' => $polo->name, 'quantity' => 1]],
            'status' => 'aprobada',
        ]);

        // Simular que ya fue usada por una venta previa
        $sale = Sale::create([
            'user_id' => $vendedor->id,
            'customer_id' => $customer->id,
            'branch_id' => 1,
            'warehouse_id' => 1,
            'document_type' => '03',
            'serie' => 'B001',
            'correlative' => 901,
            'issue_date' => now(),
            'subtotal' => 33.81,
            'igv' => 6.09,
            'total' => 39.90,
            'sunat_status' => 'aceptado',
        ]);
        \App\Services\WholesaleApprovalService::markUsed($req->id, $sale);
        $req->refresh();

        $this->assertEquals('usada', $req->status);
        $this->assertFalse($req->isUsableFor($customer->id, 1));

        $lines = [
            ['product_variant_id' => $variant->id, 'quantity' => 1],
        ];

        // Al intentar aplicar con la solicitud ya usada, no debe dar mayorista
        $result = PricingService::apply($lines, $customer->id, 1, $req->id);
        $this->assertEquals('minorista', $result['mode']);
        $this->assertEquals($variant->retail_price, $result['lines'][0]['price']);
    }

    /**
     * PREC-06: Inyección de precio manipulado (cero o negativo) es ignorada y recalculada por el servidor.
     */
    public function test_prec_06_inyeccion_de_precio_cero_o_negativo_es_ignorada(): void
    {
        $vendedor = User::where('email', 'vendedor@demo.test')->firstOrFail();
        $polo = Product::where('name', 'Polo básico algodón pima')->firstOrFail();
        $variant = $polo->variants()->firstOrFail();

        $linesWithZero = [
            ['product_variant_id' => $variant->id, 'quantity' => 1, 'price' => 0.00, 'price_reason' => 'manual'],
        ];

        $resultZero = PricingService::apply($linesWithZero, null, 1, null, $vendedor);
        // Vendedor no puede poner precio manual; debe recalcularse al precio minorista de lista
        $this->assertEquals($variant->retail_price, $resultZero['lines'][0]['price']);

        $linesWithNegative = [
            ['product_variant_id' => $variant->id, 'quantity' => 1, 'price' => -10.00, 'price_reason' => 'manual'],
        ];

        $resultNeg = PricingService::apply($linesWithNegative, null, 1, null, $vendedor);
        $this->assertEquals($variant->retail_price, $resultNeg['lines'][0]['price']);
    }

    /**
     * PREC-07: Administrador con permiso edit_sale_price fija precio manual válido.
     */
    public function test_prec_07_administrador_con_permiso_puede_fijar_precio_manual(): void
    {
        $admin = User::where('email', 'administrador@demo.test')->firstOrFail();
        $polo = Product::where('name', 'Polo básico algodón pima')->firstOrFail();
        $variant = $polo->variants()->firstOrFail();

        $lines = [
            ['product_variant_id' => $variant->id, 'quantity' => 1, 'price' => 45.00, 'price_reason' => 'manual'],
        ];

        $result = PricingService::apply($lines, null, 1, null, $admin);

        $this->assertEquals(45.00, $result['lines'][0]['price']);
        $this->assertEquals('manual', $result['lines'][0]['price_reason']);
    }
}
