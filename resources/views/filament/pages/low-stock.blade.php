<x-filament-panels::page>
    @include('filament.components.inventory-tabs', ['active' => 'low_stock'])
    {{ $this->table }}
</x-filament-panels::page>
