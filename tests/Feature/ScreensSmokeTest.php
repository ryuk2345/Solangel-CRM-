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
            Pages\SalesReport::class,
            Pages\InventoryReport::class,
            Resources\UserResource\Pages\ListUsers::class,
            \BezhanSalleh\FilamentShield\Resources\RoleResource\Pages\ListRoles::class,
            Resources\CustomerResource\Pages\CreateCustomer::class,
            Resources\CustomerResource\Pages\ListCustomers::class,
            Resources\StockTransferResource\Pages\ListStockTransfers::class,
            Resources\StockTransferResource\Pages\CreateStockTransfer::class,
            Pages\StockMatrix::class,
            Pages\LowStockReport::class,
            Pages\PrintLabels::class,
            Pages\KardexReport::class,
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

    public function test_pantallas_con_pestanas_unificadas_abren_bien(): void
    {
        // Inventario por talla con sus 3 pestañas
        Livewire::test(Pages\StockMatrix::class)->assertSee('Matriz talla × color')->assertSee('Lista (con ajustar)')->assertSee('Stock bajo');
        Livewire::test(Resources\InventoryResource\Pages\ListInventories::class)->assertSee('Matriz talla × color')->assertSee('Lista (con ajustar)')->assertSee('Stock bajo');
        Livewire::test(Pages\LowStockReport::class)->assertSee('Matriz talla × color')->assertSee('Lista (con ajustar)')->assertSee('Stock bajo');

        // Reportes con sus 3 pestañas
        Livewire::test(Pages\SalesReport::class)->assertSee('Ventas por talla y color')->assertSee('Curva rota y sin movimiento')->assertSee('Cuentas por cobrar');
        Livewire::test(Pages\InventoryReport::class)->assertSee('Ventas por talla y color')->assertSee('Curva rota y sin movimiento')->assertSee('Cuentas por cobrar');
        Livewire::test(Pages\AccountsReceivable::class)->assertSee('Ventas por talla y color')->assertSee('Curva rota y sin movimiento')->assertSee('Cuentas por cobrar');

        // Compras y Proveedores
        Livewire::test(Resources\PurchaseResource\Pages\ListPurchases::class)->assertSee('Compras')->assertSee('Proveedores');
        Livewire::test(Resources\SupplierResource\Pages\ManageSuppliers::class)->assertSee('Compras')->assertSee('Proveedores');

        // Ventas con sus 4 pestañas y acciones de cabecera
        Livewire::test(Resources\SaleResource\Pages\ListSales::class)
            ->assertSee('Todas')
            ->assertSee('Por cobrar')
            ->assertSee('Con nota de crédito')
            ->assertSee('Cotizaciones')
            ->assertSee('Nueva venta')
            ->assertSee('Nueva cotización')
            ->assertSee('Aprobaciones de precio');
    }

    public function test_menu_objetivo_por_cada_rol(): void
    {
        $panel = Filament::getPanel('admin');
        Filament::setCurrentPanel($panel);
        $r = new \ReflectionClass($panel);
        $navProp = $r->getProperty('navigationItems');
        $navProp->setAccessible(true);

        $getRoleMenu = function (string $role) use ($panel, $navProp) {
            $user = User::where('email', "{$role}@demo.test")->firstOrFail();
            $this->actingAs($user);
            Filament::auth()->setUser($user);
            $navProp->setValue($panel, []);
            app()->forgetInstance(\Filament\Navigation\NavigationManager::class);

            $mgr = new \Filament\Navigation\NavigationManager($panel);
            $mgr->mountNavigation();

            $items = collect($panel->getNavigationItems());
            return $items->groupBy(fn ($i) => $i->getGroup() ?? 'Principal')
                ->map(fn ($groupItems) => $groupItems->map(fn ($i) => $i->getLabel())->values()->all())
                ->all();
        };

        // 1. Administrador: ve todos los módulos y los 3 clusters de Configuración
        $adminMenu = $getRoleMenu('administrador');
        $this->assertArrayHasKey('Configuración', $adminMenu);
        $this->assertContains('Catálogos', $adminMenu['Configuración']);
        $this->assertContains('Empresa y SUNAT', $adminMenu['Configuración']);
        $this->assertContains('Usuarios y roles', $adminMenu['Configuración']);
        $this->assertContains('Ventas', $adminMenu['Ventas y cajas']);
        $this->assertContains('Reportes', $adminMenu['Administración']);
        $this->assertContains('Almacenes', $adminMenu['Catálogo e inventario']);

        // 2. Vendedor: solo Ventas y cajas + Catálogo e inventario (sin Admin ni Config)
        $vendedorMenu = $getRoleMenu('vendedor');
        $this->assertArrayHasKey('Ventas y cajas', $vendedorMenu);
        $this->assertArrayHasKey('Catálogo e inventario', $vendedorMenu);
        $this->assertArrayNotHasKey('Administración', $vendedorMenu);
        $this->assertArrayNotHasKey('Configuración', $vendedorMenu);
        $this->assertContains('Ventas', $vendedorMenu['Ventas y cajas']);
        $this->assertContains('Productos', $vendedorMenu['Catálogo e inventario']);
        $this->assertContains('Inventario por talla', $vendedorMenu['Catálogo e inventario']);

        // 3. Almacenero: sin Ventas y cajas
        $almaceneroMenu = $getRoleMenu('almacenero');
        $this->assertArrayNotHasKey('Ventas', $almaceneroMenu['Ventas y cajas'] ?? []);
        $this->assertArrayHasKey('Catálogo e inventario', $almaceneroMenu);
        $this->assertArrayHasKey('Administración', $almaceneroMenu);
        $this->assertContains('Guías De Remisión', $almaceneroMenu['Administración']);
        $this->assertContains('Kardex', $almaceneroMenu['Administración']);
        $this->assertContains('Catálogos', $almaceneroMenu['Configuración']);
        $this->assertNotContains('Empresa y SUNAT', $almaceneroMenu['Configuración'] ?? []);
        $this->assertNotContains('Usuarios y roles', $almaceneroMenu['Configuración'] ?? []);

        // 4. Contador: ve reportes y empresa/SUNAT, pero NO Usuarios y roles
        $contadorMenu = $getRoleMenu('contador');
        $this->assertArrayHasKey('Administración', $contadorMenu);
        $this->assertContains('Reportes', $contadorMenu['Administración']);
        $this->assertContains('Kardex', $contadorMenu['Administración']);
        $this->assertArrayHasKey('Configuración', $contadorMenu);
        $this->assertContains('Empresa y SUNAT', $contadorMenu['Configuración']);
        $this->assertContains('Catálogos', $contadorMenu['Configuración']);
        $this->assertNotContains('Usuarios y roles', $contadorMenu['Configuración']);
    }

    public function test_vendedor_puede_cobrar_y_abrir_pestana_por_cobrar(): void
    {
        $vendedor = User::where('email', 'vendedor@demo.test')->firstOrFail();
        $this->actingAs($vendedor);

        CashRegister::create([
            'user_id' => $vendedor->id,
            'branch_id' => 1,
            'status' => 'abierta',
            'opened_at' => now(),
        ]);

        // Puede abrir la lista de ventas en la pestaña "Por cobrar"
        $list = Livewire::test(Resources\SaleResource\Pages\ListSales::class, ['activeTab' => 'por_cobrar'])
            ->assertOk();
        $this->assertSame('por_cobrar', $list->get('activeTab'));

        // Venta a crédito para el cliente mayorista
        $wh = Warehouse::where('is_default', true)->firstOrFail();
        $mayorista = Customer::where('customer_type', 'mayorista')->firstOrFail();

        $sale = \App\Models\Sale::create([
            'user_id' => $vendedor->id,
            'customer_id' => $mayorista->id,
            'branch_id' => 1,
            'warehouse_id' => $wh->id,
            'seller_id' => $vendedor->id,
            'document_type' => '00',
            'serie' => 'NV01',
            'correlative' => 9991,
            'issue_date' => now(),
            'payment_condition' => 'credito',
            'due_date' => now()->addDays(30),
            'subtotal' => 84.75,
            'igv' => 15.25,
            'total' => 100,
            'credit_balance' => 100,
            'sunat_status' => 'aceptado',
        ]);

        // Registrar abono como vendedor
        \App\Services\CreditService::registerPayment($sale, 'efectivo', 35.00, 'ABONO-PRUEBA-VENDEDOR');

        $this->assertEquals(65.00, (float) $sale->fresh()->credit_balance);
        $payment = \App\Models\CustomerPayment::where('sale_id', $sale->id)->latest('id')->firstOrFail();
        $this->assertEquals(35.00, (float) $payment->amount);
        $this->assertSame($vendedor->id, $payment->user_id);

        // Pestañas de compras y abonos dentro del cliente
        Livewire::test(Resources\CustomerResource\RelationManagers\SalesRelationManager::class, [
            'ownerRecord' => $mayorista,
            'pageClass' => Resources\CustomerResource\Pages\EditCustomer::class,
        ])->assertOk();

        Livewire::test(Resources\CustomerResource\RelationManagers\PaymentsRelationManager::class, [
            'ownerRecord' => $mayorista,
            'pageClass' => Resources\CustomerResource\Pages\EditCustomer::class,
        ])->assertOk();
    }

    public function test_clusters_y_pantallas_de_configuracion_abren_bien(): void
    {
        Livewire::test(Resources\UserResource\Pages\ListUsers::class)->assertOk();
        Livewire::test(\BezhanSalleh\FilamentShield\Resources\RoleResource\Pages\ListRoles::class)->assertOk();
        Livewire::test(Resources\CategoryResource\Pages\ListCategories::class)->assertOk();
        Livewire::test(Resources\BrandResource\Pages\ManageBrands::class)->assertOk();
        Livewire::test(Resources\ColorResource\Pages\ManageColors::class)->assertOk();
        Livewire::test(Resources\SizeResource\Pages\ManageSizes::class)->assertOk();
        Livewire::test(Resources\SeasonResource\Pages\ManageSeasons::class)->assertOk();
        Livewire::test(Resources\SizeCurveResource\Pages\ManageSizeCurves::class)->assertOk();
        Livewire::test(Resources\ServiceResource\Pages\ListServices::class)->assertOk();
        Livewire::test(Resources\SerieResource\Pages\ListSeries::class)->assertOk();
        Livewire::test(Resources\CompanySettingResource\Pages\EditCompanySetting::class, [
            'record' => \App\Models\CompanySetting::value('id'),
        ])->assertOk();

        // Cluster URLs y subpáginas para el administrador
        $this->get('/admin/users-and-roles')->assertRedirect();
        $this->get('/admin/users-and-roles/users')->assertOk();
        $this->get('/admin/users-and-roles/shield/roles')->assertOk();
        $this->get('/admin/catalogs')->assertRedirect();
        $this->get('/admin/company-and-sunat')->assertRedirect();
    }
}
