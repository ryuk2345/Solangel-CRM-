<x-filament-panels::page>
    @include('filament.components.report-tabs', ['active' => 'receivable'])
    {{ $this->table }}
</x-filament-panels::page>
