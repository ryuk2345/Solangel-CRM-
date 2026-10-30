<?php

namespace Tests\Feature;

use App\Filament\Resources\QuotationResource;
use App\Filament\Resources\SaleResource;
use App\Filament\Resources\SaleResource\Pages\CreateSale;
use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Sale;
use App\Models\Serie;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Livewire\Livewire;
use Tests\TestCase;

class ZeroPriceLineBlockingTest extends TestCase
{
    use DatabaseTransactions;

    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        // Regla permanente: Confirmar sunat_sandbox = 1 antes de cualquier prueba
        $setting = CompanySetting::first();
        $this->assertNotNull($setting, 'CompanySetting debe existir');
        $this->assertEquals(1, (int) $setting->sunat_sandbox, 'sunat_sandbox debe ser 1');

        $this->superAdmin = User::whereHas('roles', fn ($q) => $q->where('name', 'super_admin'))->firstOrFail();
    }

    /**
     * Prepara datos base para una tienda (id 1 o id 2): caja abierta hoy, serie de factura, serie NV y cliente RUC.
     */
    protected function prepararEntornoTienda(int $branchId, string $sunatCode, string $serieFactura, string $serieNv): array
    {
        $branch = Branch::find($branchId);
        if (!$branch) {
            $branch = Branch::create([
                'id'         => $branchId,
                'name'       => $branchId === 1 ? 'Sucursal Principal' : 'TIENDA 2',
                'address'    => $branchId === 1 ? 'Av. Principal 100' : 'Av. Pachacútec 3261, Villa María del Triunfo',
                'sunat_code' => $sunatCode,
                'ubigeo'     => '150143',
                'department' => 'LIMA',
                'province'   => 'LIMA',
                'district'   => 'VILLA MARIA DEL TRIUNFO',
                'is_active'  => true,
            ]);
        } else {
            $branch->update([
                'sunat_code' => $sunatCode,
                'is_active'  => true,
            ]);
        }

        $warehouse = Warehouse::where('branch_id', $branch->id)->where('is_active', true)->first();
        if (!$warehouse) {
            $warehouse = Warehouse::create([
                'branch_id'  => $branch->id,
                'name'       => 'Almacén ' . $branch->name,
                'is_default' => true,
                'is_active'  => true,
            ]);
        }

        // Asegurar caja abierta hoy en la tienda
        $hoyLima = now()->setTimezone('America/Lima')->startOfDay();
        $caja = CashRegister::where('branch_id', $branch->id)
            ->where('status', 'abierta')
            ->where('opened_at', '>=', $hoyLima)
            ->first();

        if (!$caja) {
            $caja = CashRegister::create([
                'user_id'        => $this->superAdmin->id,
                'branch_id'      => $branch->id,
                'status'         => 'abierta',
                'opened_at'      => now(),
                'initial_amount' => 100.00,
                'cash_sales'     => 0,
                'cash_incomes'   => 0,
                'cash_expenses'  => 0,
                'expected_amount'=> 100.00,
            ]);
        }

        $serieF = Serie::where('branch_id', $branch->id)->where('document_type', '01')->where('is_active', true)->first();
        if (!$serieF) {
            $serieF = Serie::create([
                'branch_id'      => $branch->id,
                'document_type'  => '01',
                'serie'          => $serieFactura,
                'current_number' => 500,
                'is_active'      => true,
            ]);
        }

        $serieNotaVenta = Serie::where('branch_id', $branch->id)->where('document_type', '00')->where('is_active', true)->first();
        if (!$serieNotaVenta) {
            $serieNotaVenta = Serie::create([
                'branch_id'      => $branch->id,
                'document_type'  => '00',
                'serie'          => $serieNv,
                'current_number' => 500,
                'is_active'      => true,
            ]);
        }

        $customer = Customer::where('document_type', '6')->first();
        if (!$customer) {
            $customer = Customer::create([
                'document_type'   => '6',
                'document_number' => '20600000001',
                'name'            => 'EMPRESA CLIENTE PRUEBA SAC',
                'address'         => 'AV. LIMA 123',
            ]);
        }

        $genericProduct = Product::firstOrCreate(
            ['name' => 'Repuesto Varios / Genérico'],
            ['is_service' => true, 'sale_price' => 0, 'is_web_visible' => false]
        );
        $genericVariant = ProductVariant::firstOrCreate(
            ['product_id' => $genericProduct->id],
            ['sku' => 'REPE-GENERICO', 'color_name' => 'Único', 'size' => 'Único']
        );

        return compact('branch', 'warehouse', 'caja', 'serieF', 'serieNotaVenta', 'customer', 'genericVariant');
    }

    /**
     * En Sucursal Principal (id 1) y Tienda 2 (id 2):
     * Una factura con línea en 0 se bloquea al guardarse en Nueva Venta,
     * sin crear la venta ni consumir correlativo; y una Nota de Venta sí se permite.
     */
    public function test_nueva_venta_bloquea_factura_con_linea_precio_cero_en_las_dos_tiendas(): void
    {
        $this->actingAs($this->superAdmin);

        $tiendas = [
            ['id' => 1, 'code' => '0000', 'serie_f' => 'F001', 'serie_nv' => 'NV01'],
            ['id' => 2, 'code' => '0002', 'serie_f' => 'F021', 'serie_nv' => 'NV02'],
        ];

        foreach ($tiendas as $t) {
            $env = $this->prepararEntornoTienda($t['id'], $t['code'], $t['serie_f'], $t['serie_nv']);
            $correlativoAntes = $env['serieF']->current_number;
            $ventasAntes = Sale::count();

            $component = new class extends CreateSale {
                public function testMutate(array $data, array $rawState): array
                {
                    $this->data = $rawState;
                    return $this->mutateFormDataBeforeCreate($data);
                }
            };

            $itemsConCero = [
                'item1' => [
                    'product_variant_id' => $env['genericVariant']->id,
                    'product_name'       => 'MANGUERA + ABRAZADERAS + CHUCK',
                    'quantity'           => 1,
                    'price'              => 100.00,
                    'total'              => 100.00,
                    'igv'                => 15.25,
                ],
                'item2' => [
                    'product_variant_id' => $env['genericVariant']->id,
                    'product_name'       => 'PLACA: A0R806',
                    'quantity'           => 1,
                    'price'              => 0.00,
                    'total'              => 0.00,
                    'igv'                => 0.00,
                ],
            ];

            $dataFactura = [
                'customer_id'   => $env['customer']->id,
                'branch_id'     => $env['branch']->id,
                'warehouse_id'  => $env['warehouse']->id,
                'serie'         => $env['serieF']->serie,
                'issue_date'    => now()->format('Y-m-d H:i:s'),
                'seller_id'     => $this->superAdmin->id,
                'is_quick_sale' => false,
                'items'         => $itemsConCero,
                'payments'      => [
                    ['payment_method' => 'efectivo', 'amount' => 100.00],
                ],
            ];

            $bloqueado = false;
            try {
                $component->testMutate($dataFactura, $dataFactura);
            } catch (\Filament\Support\Exceptions\Halt $e) {
                $bloqueado = true;
            }

            $this->assertTrue($bloqueado, "Tienda {$t['id']}: La factura con línea en 0 debió bloquearse con Halt.");
            $this->assertEquals($correlativoAntes, $env['serieF']->fresh()->current_number, "Tienda {$t['id']}: No debe consumir correlativo al bloquear.");
            $this->assertEquals($ventasAntes, Sale::count(), "Tienda {$t['id']}: No debe crear la venta.");

            // Verificar que Nota de Venta (NV) SÍ lo permite en ambas tiendas
            $dataNotaVenta = $dataFactura;
            $dataNotaVenta['serie'] = $env['serieNotaVenta']->serie;

            $resNv = $component->testMutate($dataNotaVenta, $dataNotaVenta);
            $this->assertEquals('00', $resNv['document_type'], "Tienda {$t['id']}: La Nota de Venta con línea en 0 sí debe permitirse.");
        }
    }

    /**
     * En Sucursal Principal (id 1) y Tienda 2 (id 2):
     * Convertir una Cotización con línea en 0 a Factura se bloquea sin crear venta ni consumir correlativo.
     */
    public function test_cotizacion_con_linea_cero_se_bloquea_al_convertir_en_factura_en_ambas_tiendas(): void
    {
        $this->actingAs($this->superAdmin);

        $tiendas = [
            ['id' => 1, 'code' => '0000', 'serie_f' => 'F001', 'serie_nv' => 'NV01'],
            ['id' => 2, 'code' => '0002', 'serie_f' => 'F021', 'serie_nv' => 'NV02'],
        ];

        foreach ($tiendas as $t) {
            $env = $this->prepararEntornoTienda($t['id'], $t['code'], $t['serie_f'], $t['serie_nv']);
            $correlativoAntes = $env['serieF']->current_number;

            $quotation = Quotation::create([
                'customer_id' => $env['customer']->id,
                'user_id'     => $this->superAdmin->id,
                'branch_id'   => $env['branch']->id,
                'correlative' => 'COT-TEST-' . $t['id'] . '-' . rand(1000, 9999),
                'issue_date'  => now(),
                'subtotal'    => 84.75,
                'igv'         => 15.25,
                'total'       => 100.00,
                'status'      => 'pendiente',
            ]);

            QuotationItem::create([
                'quotation_id'       => $quotation->id,
                'product_variant_id' => $env['genericVariant']->id,
                'product_name'       => 'REPUESTO PRINCIPAL',
                'quantity'           => 1,
                'price'              => 100.00,
                'subtotal'           => 84.75,
                'igv'                => 15.25,
                'total'              => 100.00,
            ]);

            QuotationItem::create([
                'quotation_id'       => $quotation->id,
                'product_variant_id' => $env['genericVariant']->id,
                'product_name'       => 'PLACA: A0R806',
                'quantity'           => 1,
                'price'              => 0.00,
                'subtotal'           => 0.00,
                'igv'                => 0.00,
                'total'              => 0.00,
            ]);

            Livewire::test(QuotationResource\Pages\ListQuotations::class)
                ->callTableAction('aprobar_venta', $quotation, data: [
                    'serie'             => $env['serieF']->serie,
                    'payment_condition' => 'contado',
                    'payment_method'    => 'efectivo',
                ])
                ->assertNotified('Línea con precio 0 no permitida');

            $this->assertEquals('pendiente', $quotation->fresh()->status, "Tienda {$t['id']}: La cotización debe seguir pendiente.");
            $this->assertEquals($correlativoAntes, $env['serieF']->fresh()->current_number, "Tienda {$t['id']}: No debe consumir correlativo.");
            $this->assertFalse(Sale::where('quotation_id', $quotation->id)->exists(), "Tienda {$t['id']}: No debe crearse la venta.");
        }
    }

    /**
     * En Sucursal Principal (id 1) y Tienda 2 (id 2):
     * Auditar una Venta Rápida con una línea en precio 0 se bloquea en boleta/factura.
     */
    public function test_auditar_venta_rapida_con_linea_cero_se_bloquea_en_ambas_tiendas(): void
    {
        $this->actingAs($this->superAdmin);

        $tiendas = [
            ['id' => 1, 'code' => '0000', 'serie_f' => 'F001', 'serie_nv' => 'NV01'],
            ['id' => 2, 'code' => '0002', 'serie_f' => 'F021', 'serie_nv' => 'NV02'],
        ];

        foreach ($tiendas as $t) {
            $env = $this->prepararEntornoTienda($t['id'], $t['code'], $t['serie_f'], $t['serie_nv']);

            $quickSale = Sale::create([
                'customer_id'            => $env['customer']->id,
                'user_id'                => $this->superAdmin->id,
                'seller_id'              => $this->superAdmin->id,
                'branch_id'              => $env['branch']->id,
                'warehouse_id'           => $env['warehouse']->id,
                'document_type'          => '01',
                'serie'                  => $env['serieF']->serie,
                'correlative'            => 700 + $t['id'],
                'issue_date'             => now(),
                'subtotal'               => 84.75,
                'igv'                    => 15.25,
                'total'                  => 100.00,
                'payment_condition'      => 'contado',
                'payment_method'         => 'efectivo',
                'sunat_status'           => 'pendiente',
                'is_quick_sale'          => true,
                'is_audited'             => false,
                'quick_sale_description' => 'Venta rápida de prueba',
            ]);

            Livewire::test(SaleResource\Pages\ListSales::class)
                ->mountTableAction('auditar_venta', $quickSale)
                ->set('mountedTableActionsData.0.audited_items', [
                    'row1' => [
                        'product_variant_id' => $env['genericVariant']->id,
                        'quantity'           => 1,
                        'price'              => 100.00,
                    ],
                    'row2' => [
                        'product_variant_id' => $env['genericVariant']->id,
                        'quantity'           => 1,
                        'price'              => 0.00,
                    ],
                ])
                ->callMountedTableAction()
                ->assertHasNoTableActionErrors()
                ->assertNotified('Línea con precio 0 no permitida');

            $this->assertFalse($quickSale->fresh()->is_audited, "Tienda {$t['id']}: La venta rápida no debe marcarse como auditada si tiene línea en 0.");
            $this->assertEquals(0, $quickSale->items()->count(), "Tienda {$t['id']}: No deben crearse ítems auditados con línea en 0.");

            // 2. Si la suma de líneas auditadas no coincide con el total cobrado, no deja guardar y explica la diferencia
            Livewire::test(SaleResource\Pages\ListSales::class)
                ->mountTableAction('auditar_venta', $quickSale)
                ->set('mountedTableActionsData.0.audited_items', [
                    'row1' => [
                        'product_variant_id' => $env['genericVariant']->id,
                        'quantity'           => 1,
                        'price'              => 80.00,
                    ],
                ])
                ->callMountedTableAction()
                ->assertHasNoTableActionErrors()
                ->assertNotified('El total auditado no coincide con el total cobrado');

            $this->assertFalse($quickSale->fresh()->is_audited, "Tienda {$t['id']}: No debe auditar si la suma no coincide con el total cobrado.");
        }
    }
}

