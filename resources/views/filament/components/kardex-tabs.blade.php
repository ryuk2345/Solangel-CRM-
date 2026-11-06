@props(['active' => 'valued'])

<div class="mb-4">
    <x-filament::tabs>
        <x-filament::tabs.item
            :active="$active === 'valued'"
            :href="\App\Filament\Pages\KardexReport::getUrl()"
            tag="a"
            icon="heroicon-o-book-open"
        >
            Kardex valorizado
        </x-filament::tabs.item>

        <x-filament::tabs.item
            :active="$active === 'movements'"
            :href="\App\Filament\Resources\StockMovementResource::getUrl()"
            tag="a"
            icon="heroicon-o-arrows-right-left"
        >
            Movimientos (auditoría)
        </x-filament::tabs.item>
    </x-filament::tabs>
</div>

