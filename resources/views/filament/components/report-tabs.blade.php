@props(['active' => 'sales'])

<div class="mb-4">
    <x-filament::tabs>
        <x-filament::tabs.item
            :active="$active === 'sales'"
            :href="\App\Filament\Pages\SalesReport::getUrl()"
            tag="a"
            icon="heroicon-o-chart-bar"
        >
            Ventas por talla y color
        </x-filament::tabs.item>

        <x-filament::tabs.item
            :active="$active === 'inventory'"
            :href="\App\Filament\Pages\InventoryReport::getUrl()"
            tag="a"
            icon="heroicon-o-presentation-chart-line"
        >
            Curva rota y sin movimiento
        </x-filament::tabs.item>

        <x-filament::tabs.item
            :active="$active === 'receivable'"
            :href="\App\Filament\Pages\AccountsReceivable::getUrl()"
            tag="a"
            icon="heroicon-o-banknotes"
            :badge="\App\Models\Sale::where('payment_condition', 'credito')->where('credit_balance', '>', 0)->count() ?: null"
            badge-color="warning"
        >
            Cuentas por cobrar (con abonos)
        </x-filament::tabs.item>
    </x-filament::tabs>
</div>
