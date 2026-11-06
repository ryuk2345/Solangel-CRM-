@props(['active' => 'matrix'])

<div class="mb-4">
    <x-filament::tabs>
        <x-filament::tabs.item
            :active="$active === 'matrix'"
            :href="\App\Filament\Pages\StockMatrix::getUrl()"
            tag="a"
            icon="heroicon-o-table-cells"
        >
            Matriz talla × color
        </x-filament::tabs.item>

        <x-filament::tabs.item
            :active="$active === 'list'"
            :href="\App\Filament\Resources\InventoryResource::getUrl()"
            tag="a"
            icon="heroicon-o-list-bullet"
        >
            Lista (con ajustar)
        </x-filament::tabs.item>

        <x-filament::tabs.item
            :active="$active === 'low_stock'"
            :href="\App\Filament\Pages\LowStockReport::getUrl()"
            tag="a"
            icon="heroicon-o-exclamation-triangle"
            :badge="\App\Models\Inventory::lowStock()->count() ?: null"
            badge-color="danger"
        >
            Stock bajo
        </x-filament::tabs.item>
    </x-filament::tabs>
</div>
