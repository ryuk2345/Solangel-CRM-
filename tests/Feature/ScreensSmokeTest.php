<?php

namespace Tests\Feature;

use App\Filament\Pages;
use App\Filament\Resources;
use App\Models\CashRegister;
use App\Models\Customer;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\PurchaseService;
use App\Services\WholesaleApprovalService;
use Database\Seeders\DemoDataSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Abre cada pantalla con datos reales (demo) y prueba los buscadores principales,
 * para detectar errores que solo aparecen al usar el sistema en el navegador.
 */
class ScreensSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected bool $seed = true;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('DEMO_PASSWORD=secret-demo');
        $this->seed(DemoDataSeeder::class);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->admin = User::where('email', 'administrador@demo.test')->firstOrFail();
        $this->actingAs($this->admin);

        CashRegister::create(['user_id' => $this->admin->id, 'branch_id' => 1, 'status' => 'abierta', 'opened_at' => now()]);

        $wh = Warehouse::where('is_default', true)->firstOrFail();
        $variant = ProductVariant::whereHas('product', fn ($p) => $p->where('is_service', false))->firstOrFail();
        $supplier = Supplier::create(['name' => 'Proveedor demo', 'document_number' => '20123456789']);
        PurchaseService::register(['supplier_id' => $supplier->id, 'warehouse_id' => $wh->id], [
            ['product_variant_id' => $variant->id, 'quantity' => 2, 'unit_cost' => 10],
        ]);
        WholesaleApprovalService::request(
            Customer::where('customer_type', 'mayorista')->value('id'), 1,
            [['product_variant_id' => $variant->id, 'quantity' => 1]],
            User::where('email', 'vendedor@demo.test')->first(),
        );
    }

    public function test_todas_las_pantallas_abren_con_datos(): void
    {
        $pages = [
            Resources\ProductResource\Pages\ListProducts::class,
            Resources\ProductResource\Pages\CreateProduct::class,
            Resources\ProductVariantResource\Pages\ListProductVariants::class,
            Resources\InventoryResource\Pages\ListInventories::class,
            Resources\StockMovementResource\Pages\ListStockMovements::class,
            Resources\PurchaseResource\Pages\ListPurchases::class,
            Resources\PurchaseResource\Pages\CreatePurchase::class,
            Resources\SupplierResource\Pages\ManageSuppliers::class,
            Resources\SaleResource\Pages\ListSales::class,
            Resources\SaleResource\Pages\CreateSale::class,
            Resources\QuotationResource\Pages\ListQuotations::class,
            Resources\QuotationResource\Pages\CreateQuotation::class,
            Resources\WholesalePriceRequestResource\Pages\ListWholesalePriceRequests::class,
            Resources\SaleReturnResource\Pages\ListSaleReturns::class,
            Resources\CustomerPaymentResource\Pages\ListCustomerPayments::class,
            Resources\CustomerResource\Pages\EditCustomer::class => null,
            Pages\AccountsReceivable::class,
            Resources\CustomerPaymentResource\Pages\ListCustomerPayments::class,
            Pages\AccountsReceivable::class,
            Resources\CustomerResource\Pages\CreateCustomer::class,
            Resources\CustomerResource\Pages\ListCustomers::class,
            Resources\StockTransferResource\Pages\ListStockTransfers::class,
            Resources\StockTransferResource\Pages\CreateStockTransfer::class,
            Pages\StockMatrix::class,
            Pages\LowStockReport::class,
            Pages\PrintLabels::class,
        ];

        foreach ($pages as $key => $page) {
            if ($page === null) {
                continue; // páginas que necesitan un registro: se prueban abajo
            }
            Livewire::test($page)->assertOk();
        }
        Livewire::test(Resources\CustomerResource\Pages\EditCustomer::class, ['record' => Customer::where('customer_type', 'mayorista')->value('id')])->assertOk();

        Livewire::test(Resources\PurchaseResource\Pages\ViewPurchase::class, ['record' => \App\Models\Purchase::value('id')])->assertOk();
        Livewire::test(Resources\ProductResource\Pages\EditProduct::class, ['record' => \App\Models\Product::where('is_service', false)->value('id')])->assertOk();

        // Filtros y matriz con un modelo elegido
        Livewire::test(Resources\InventoryResource\Pages\ListInventories::class)->set('tableFilters.low_stock.isActive', true)->assertOk();
        Livewire::test(Pages\LowStockReport::class)->set('tableFilters.agotados.isActive', true)->assertOk();
        Livewire::test(Pages\StockMatrix::class)->set('data.product_id', \App\Models\Product::where('is_service', false)->value('id'))->assertSee('Total por talla');
    }

    public function test_buscadores_de_prendas_responden(): void
    {
        $sku = ProductVariant::whereHas('product', fn ($p) => $p->where('is_service', false))->value('sku');
        $prefix = substr($sku, 0, 3);

        $sale = Livewire::test(Resources\SaleResource\Pages\CreateSale::class)->set('data.items', ['a' => ['quantity' => 1]]);
        $this->assertNotEmpty($sale->instance()->getFormSelectSearchResults('data.items.a.product_variant_id', $prefix));

        $purchase = Livewire::test(Resources\PurchaseResource\Pages\CreatePurchase::class)->set('data.lines', ['a' => ['quantity' => 1]]);
        $this->assertNotEmpty($purchase->instance()->getFormSelectSearchResults('data.lines.a.product_variant_id', $prefix));

        $labels = Livewire::test(Pages\PrintLabels::class);
        $key = array_key_first($labels->get('data.lines'));
        $this->assertNotEmpty($labels->instance()->getFormSelectSearchResults("data.lines.{$key}.product_variant_id", $prefix));

        $matrix = Livewire::test(Pages\StockMatrix::class);
        $this->assertNotEmpty($matrix->instance()->getFormSelectSearchResults('data.product_id', 'Polo'));

        $quote = Livewire::test(Resources\QuotationResource\Pages\CreateQuotation::class)->set('data.items', ['a' => ['quantity' => 1]]);
        $this->assertNotEmpty($quote->instance()->getFormSelectSearchResults('data.items.a.product_variant_id', $prefix));
    }
}
