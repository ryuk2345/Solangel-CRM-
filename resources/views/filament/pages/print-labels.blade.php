<x-filament-panels::page>
    <form wire:submit="generate" class="space-y-6">
        {{ $this->form }}

        <x-filament::button type="submit" icon="heroicon-o-printer">
            Generar etiquetas (PDF 50 × 30 mm)
        </x-filament::button>
    </form>
</x-filament-panels::page>
