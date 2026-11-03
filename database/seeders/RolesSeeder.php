<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Roles acordados con el cliente (PLAN_MODA §4 y §8):
 * administrador, vendedor, almacenero y contador.
 * super_admin queda como rol técnico del proveedor (Gate::before le da todo).
 *
 * Se puede ejecutar varias veces: regenera los permisos de Shield y vuelve a
 * sincronizar los de cada rol. Ejecutar de nuevo al agregar pantallas nuevas:
 *   php artisan db:seed --class=RolesSeeder
 */
class RolesSeeder extends Seeder
{
    /** Permisos propios del negocio, además de los de Shield. */
    public const CUSTOM_PERMISSIONS = [
        'authorize_wholesale_price' => 'Autorizar precio mayorista para 1 prenda (con PIN)',
        'edit_sale_price'           => 'Cambiar el precio a mano en una venta',
        'view_cost_price'           => 'Ver costos de compra',
        'export_reports'            => 'Exportar reportes',
        'void_sale'                 => 'Anular ventas y emitir notas de crédito',
        'manage_credit'             => 'Habilitar crédito a clientes y exceder el límite',
        'print_labels'              => 'Imprimir etiquetas',
        'exchange_sale'             => 'Registrar cambios de talla o color (nota de crédito parcial)',
    ];

    private const READ = ['view_any', 'view'];
    private const WRITE = ['view_any', 'view', 'create', 'update'];

    public function run(): void
    {
        Artisan::call('shield:generate', [
            '--all' => true,
            '--option' => 'permissions',
            '--panel' => 'admin',
            '--minimal' => true,
            '--no-interaction' => true,
        ]);

        foreach (array_keys(self::CUSTOM_PERMISSIONS) as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $all = Permission::where('guard_name', 'web')->pluck('name');

        $roles = [
            // Acceso total a todas las tiendas (decisión 1)
            'administrador' => $all->all(),

            'vendedor' => $this->pick($all, [
                'sale' => ['view_any', 'view', 'create'],
                'quotation' => self::WRITE,
                'customer' => self::WRITE,
                'customer::payment' => ['view_any', 'view', 'create'],
                'cash::register' => self::WRITE,
                'wholesale::price::request' => ['view_any', 'view', 'create'],
                'sale::return' => ['view_any', 'view'],
                'product' => self::READ,
                'product::variant' => self::READ,
                'inventory' => self::READ,
            ], ['page_Dashboard', 'page_StockMatrix', 'page_AccountsReceivable', 'widget_LatestSalesWidget', 'exchange_sale']),

            'almacenero' => $this->pick($all, [
                'product' => self::WRITE,
                'product::variant' => self::WRITE,
                'inventory' => self::WRITE,
                'stock::transfer' => self::WRITE,
                'despatch' => self::WRITE,
                'purchase' => self::WRITE,
                'supplier' => self::WRITE,
                'warehouse' => self::READ,
                'category' => self::READ,
                'brand' => self::READ,
                'season' => self::READ,
                'color' => self::READ,
                'size' => self::READ,
                'size::curve' => self::READ,
                'stock::movement' => self::READ,
            ], ['page_Dashboard', 'page_StockMatrix', 'page_LowStockReport', 'page_PrintLabels', 'widget_LowStockWidget', 'view_cost_price', 'print_labels']),

            // Solo lectura de todo, con exportación (decisión de roles)
            'contador' => $all->filter(fn ($p) => str_starts_with($p, 'view_') || str_starts_with($p, 'page_') || str_starts_with($p, 'widget_'))
                ->reject(fn ($p) => str_contains($p, '_role') || $p === 'view_any_role')
                ->merge(['export_reports', 'view_cost_price'])
                ->values()->all(),
        ];

        foreach ($roles as $roleName => $permissions) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $role->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info('✅ Roles: administrador, vendedor, almacenero y contador (' . $all->count() . ' permisos).');
    }

    /**
     * @param array<string, array<int,string>> $map  clave de Shield => acciones
     * @param array<int,string> $extra  permisos sueltos (páginas, widgets, propios)
     */
    private function pick($all, array $map, array $extra = []): array
    {
        $wanted = [];
        foreach ($map as $key => $actions) {
            foreach ($actions as $action) {
                $wanted[] = "{$action}_{$key}";
            }
        }

        return $all->intersect(array_merge($wanted, $extra))->values()->all();
    }
}
