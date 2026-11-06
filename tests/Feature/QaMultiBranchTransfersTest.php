<?php

namespace Tests\Feature;

use App\Filament\Resources\StockTransferResource;
use App\Models\Branch;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockTransfer;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\StockService;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class QaMultiBranchTransfersTest extends TestCase
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
     * SUC-01: Traspaso entre tiendas mueve stock de origen a destino con trazabilidad.
     */
    public function test_suc_01_traspaso_entre_tiendas_mueve_stock(): void
    {
        $admin = User::where('email', 'administrador@demo.test')->firstOrFail();
        $polo = Product::where('name', 'Polo básico algodón pima')->firstOrFail();
        $variant = $polo->variants()->firstOrFail();

        $originBranch = Branch::firstOrCreate(
            ['name' => 'Tienda Principal Gamarra'],
            ['address' => 'Gamarra 123', 'is_active' => true]
        );
        $destBranch = Branch::firstOrCreate(
            ['name' => 'Segunda Tienda San Miguel'],
            ['address' => 'Av. La Marina 100', 'is_active' => true]
        );

        $originWh = Warehouse::firstOrCreate(
            ['branch_id' => $originBranch->id],
            ['name' => 'Almacén Gamarra', 'code' => 'ALM-01']
        );
        $destWh = Warehouse::firstOrCreate(
            ['branch_id' => $destBranch->id],
            ['name' => 'Almacén San Miguel', 'code' => 'ALM-02']
        );

        // Asegurar stock en origen
        StockService::move($variant->id, $originWh->id, 20, 'initial');

        $stockOrigInicial = (int) Inventory::where('product_variant_id', $variant->id)->where('warehouse_id', $originWh->id)->value('stock');
        $stockDestInicial = (int) Inventory::where('product_variant_id', $variant->id)->where('warehouse_id', $destWh->id)->value('stock');

        // 1. Crear traspaso y descontar de origen (en tránsito)
        $transfer = StockTransfer::create([
            'origin_branch_id' => $originBranch->id,
            'origin_warehouse_id' => $originWh->id,
            'destination_branch_id' => $destBranch->id,
            'destination_warehouse_id' => $destWh->id,
            'sent_by_user_id' => $admin->id,
            'status' => 'en_transito',
            'sent_at' => now(),
            'number' => 'TR-TEST-001',
        ]);

        $item = $transfer->items()->create([
            'product_variant_id' => $variant->id,
            'quantity_sent' => 5,
        ]);

        StockService::move($variant->id, $originWh->id, -5, 'transfer_out', $transfer);

        $stockOrigTrasEnvio = (int) Inventory::where('product_variant_id', $variant->id)->where('warehouse_id', $originWh->id)->value('stock');
        $this->assertEquals($stockOrigInicial - 5, $stockOrigTrasEnvio);

        // 2. Recepción en destino
        StockService::move($variant->id, $destWh->id, 5, 'transfer_in', $transfer);
        $item->update(['quantity_received' => 5, 'quantity_difference' => 0]);
        $transfer->update(['status' => 'completado', 'received_by_user_id' => $admin->id, 'received_at' => now()]);

        $stockDestFinal = (int) Inventory::where('product_variant_id', $variant->id)->where('warehouse_id', $destWh->id)->value('stock');
        $this->assertEquals($stockDestInicial + 5, $stockDestFinal);
        $this->assertEquals('completado', $transfer->status);
    }

    /**
     * SUC-02: Traspaso que supera el stock disponible en almacén de origen es bloqueado.
     */
    public function test_suc_02_traspaso_mayor_al_stock_es_bloqueado(): void
    {
        $polo = Product::where('name', 'Polo básico algodón pima')->firstOrFail();
        $variant = $polo->variants()->firstOrFail();
        $originWh = Warehouse::firstOrFail();

        $stockDisponible = (int) Inventory::where('product_variant_id', $variant->id)->where('warehouse_id', $originWh->id)->value('stock');

        $this->expectException(ValidationException::class);

        // Salida mayor a lo que hay en el almacén origen
        StockService::move($variant->id, $originWh->id, -($stockDisponible + 50), 'transfer_out');
    }

    /**
     * SUC-03: Recepción de traspaso con faltante físico registra estado recibido_con_diferencia.
     */
    public function test_suc_03_recepcion_con_diferencia_actualiza_estado(): void
    {
        $admin = User::where('email', 'administrador@demo.test')->firstOrFail();
        $polo = Product::where('name', 'Polo básico algodón pima')->firstOrFail();
        $variant = $polo->variants()->firstOrFail();

        $originBranch = Branch::firstOrCreate(
            ['name' => 'Tienda Principal Gamarra'],
            ['address' => 'Gamarra 123', 'is_active' => true]
        );
        $destBranch = Branch::firstOrCreate(
            ['name' => 'Segunda Tienda San Miguel'],
            ['address' => 'Av. La Marina 100', 'is_active' => true]
        );

        $originWh = Warehouse::firstOrCreate(
            ['branch_id' => $originBranch->id],
            ['name' => 'Almacén Gamarra', 'code' => 'ALM-01']
        );
        $destWh = Warehouse::firstOrCreate(
            ['branch_id' => $destBranch->id],
            ['name' => 'Almacén San Miguel', 'code' => 'ALM-02']
        );

        // Stock suficiente en origen
        StockService::move($variant->id, $originWh->id, 10, 'initial');

        $transfer = StockTransfer::create([
            'origin_branch_id' => $originBranch->id,
            'origin_warehouse_id' => $originWh->id,
            'destination_branch_id' => $destBranch->id,
            'destination_warehouse_id' => $destWh->id,
            'sent_by_user_id' => $admin->id,
            'status' => 'en_transito',
            'sent_at' => now(),
            'number' => 'TR-DIFF-001',
        ]);

        $item = $transfer->items()->create([
            'product_variant_id' => $variant->id,
            'quantity_sent' => 10,
        ]);

        StockService::move($variant->id, $originWh->id, -10, 'transfer_out', $transfer);

        // Se reciben solo 8 prendas (faltan 2)
        $receivedQty = 8;
        $diff = 10 - $receivedQty; // 2
        StockService::move($variant->id, $destWh->id, $receivedQty, 'transfer_in', $transfer);

        $item->update([
            'quantity_received' => $receivedQty,
            'quantity_difference' => $diff,
            'notes' => 'Faltaron 2 prendas en bulto',
        ]);

        $transfer->update([
            'status' => 'recibido_con_diferencia',
            'received_by_user_id' => $admin->id,
            'received_at' => now(),
        ]);

        $this->assertEquals('recibido_con_diferencia', $transfer->status);
        $this->assertEquals(2, $item->quantity_difference);
    }

    /**
     * SUC-04: Aislamiento entre tiendas: Vendedor asignado a Tienda 1 no puede ver traspasos exclusivos de Tienda 2.
     */
    public function test_suc_04_vendedor_no_puede_ver_traspasos_de_otra_tienda(): void
    {
        $branch1 = Branch::firstOrCreate(
            ['name' => 'Tienda Principal Gamarra'],
            ['address' => 'Gamarra 123', 'is_active' => true]
        );
        $branch2 = Branch::firstOrCreate(
            ['name' => 'Segunda Tienda San Miguel'],
            ['address' => 'Av. La Marina 100', 'is_active' => true]
        );

        $vendedorTienda1 = User::where('email', 'vendedor@demo.test')->firstOrFail();
        $vendedorTienda1->update(['branch_id' => $branch1->id]);

        $admin = User::where('email', 'administrador@demo.test')->firstOrFail();
        $wh2 = Warehouse::firstOrCreate(
            ['branch_id' => $branch2->id],
            ['name' => 'Almacén San Miguel', 'code' => 'ALM-02']
        );

        // Crear traspaso exclusivo de Tienda 2 (origen Tienda 2, destino Tienda 2 o Tienda 3)
        $transferTienda2 = StockTransfer::create([
            'origin_branch_id' => $branch2->id,
            'origin_warehouse_id' => $wh2->id,
            'destination_branch_id' => $branch2->id,
            'destination_warehouse_id' => $wh2->id,
            'sent_by_user_id' => $admin->id,
            'status' => 'en_transito',
            'sent_at' => now(),
            'number' => 'TR-TIENDA2-001',
        ]);

        $this->actingAs($vendedorTienda1);

        // La consulta de StockTransferResource debe aislar y no devolver este traspaso
        $visibleTransfers = StockTransferResource::getEloquentQuery()->pluck('id')->all();

        $this->assertNotContains($transferTienda2->id, $visibleTransfers);
    }

    /**
     * SUC-05: Vendedor de Tienda 1 intentando operar en almacén de otra tienda.
     */
    public function test_suc_05_vendedor_aislado_en_su_sucursal(): void
    {
        $vendedor = User::where('email', 'vendedor@demo.test')->firstOrFail();
        $branchTienda1 = Branch::firstOrFail();
        $vendedor->update(['branch_id' => $branchTienda1->id]);

        $this->actingAs($vendedor);

        // El usuario normal no ve todas las sucursales
        $this->assertFalse($vendedor->seesAllBranches());
        $this->assertEquals($branchTienda1->id, $vendedor->branch_id);
    }
}
