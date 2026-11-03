<?php

namespace App\Filament\Pages;

use App\Models\Warehouse;
use App\Services\ReportService;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

/** Curva rota y stock sin movimiento (Etapa 9). */
class InventoryReport extends Page implements HasForms
{
    use HasPageShield;
    use InteractsWithForms;

    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';
    protected static ?string $navigationGroup = 'Administración';
    protected static ?string $navigationLabel = 'Curva rota y stock sin movimiento';
    protected static ?string $title = 'Curva rota y stock sin movimiento';
    protected static ?int $navigationSort = 2;
    protected static string $view = 'filament.pages.inventory-report';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(['days' => 90]);
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->columns(3)->schema([
            Forms\Components\Select::make('warehouse_id')->label('Almacén')->placeholder('Todos')
                ->options(fn () => Warehouse::query()
                    ->when(!auth()->user()?->seesAllBranches(), fn ($q) => $q->where('branch_id', auth()->user()?->branch_id ?? 1))
                    ->where('is_active', true)->pluck('name', 'id'))
                ->live(),
            Forms\Components\TextInput::make('days')->label('Sin ventas hace más de (días)')->numeric()->minValue(1)->required()->live(onBlur: true),
        ]);
    }

    private function scope(): array
    {
        $branchId = auth()->user()?->seesAllBranches() ? null : auth()->user()?->branch_id;

        return [$this->data['warehouse_id'] ?? null, $branchId];
    }

    public function getBroken()
    {
        [$wh, $branch] = $this->scope();

        return ReportService::brokenCurves($wh ? (int) $wh : null, $branch);
    }

    public function getDead()
    {
        [$wh, $branch] = $this->scope();

        return ReportService::deadStock(max(1, (int) ($this->data['days'] ?? 90)), $wh ? (int) $wh : null, $branch);
    }

    public function exportBroken()
    {
        abort_unless(auth()->user()?->can('export_reports'), 403);

        return ReportService::csv('curva-rota-' . now()->format('Ymd') . '.csv',
            ['Modelo', 'Color', 'Almacén', 'Tallas agotadas', 'Tallas con stock', 'Unidades'],
            $this->getBroken()->map(fn ($r) => [$r['model'], $r['color'], $r['warehouse'], implode(', ', $r['missing']), implode(', ', $r['available']), $r['units']]));
    }

    public function exportDead()
    {
        abort_unless(auth()->user()?->can('export_reports'), 403);
        $showCost = auth()->user()?->can('view_cost_price');

        return ReportService::csv('stock-sin-movimiento-' . now()->format('Ymd') . '.csv',
            array_merge(['Modelo', 'Color', 'Talla', 'Almacén', 'Stock', 'Última venta', 'Días'], $showCost ? ['Valor al costo'] : []),
            $this->getDead()->map(fn ($r) => array_merge(
                [$r['model'], $r['color'], $r['size'], $r['warehouse'], $r['stock'], $r['last_sale'] ?? 'Nunca', $r['days']],
                $showCost ? [number_format($r['cost_value'], 2, '.', '')] : [],
            )));
    }
}
