<?php

namespace App\Filament\Pages;

use App\Exports\KardexExcelExport;
use App\Models\Category;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\KardexService;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Kardex valorizado con método promedio ponderado móvil y formato del cliente (Sección 3). */
class KardexReport extends Page implements HasForms
{
    use HasPageShield;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-book-open';
    protected static ?string $navigationGroup = 'Administración';
    protected static ?string $navigationLabel = 'Kardex';
    protected static ?string $title = 'Kardex Valorizado';
    protected static ?int $navigationSort = 3;
    protected static string $view = 'filament.pages.kardex-report';

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if (!$user) {
            return false;
        }

        return $user->seesAllBranches()
            || $user->hasRole(['super_admin', 'administrador', 'contador', 'almacenero'])
            || $user->can('view_any_stock::movement')
            || $user->can('page_KardexReport');
    }

    public ?array $data = [];

    /** Modo de visualización: 'summary' (inventario general) o 'detail' (ficha kardex detallada). */
    public string $viewMode = 'summary';

    /** SKU seleccionado para ver su ficha Kardex individual. */
    public ?string $selectedSku = null;

    /** Almacén seleccionado para la ficha individual. */
    public ?string $selectedWarehouseName = null;

    public function showKardex(string $sku, ?string $warehouseName = null): void
    {
        $this->selectedSku = $sku;
        $this->selectedWarehouseName = $warehouseName;
        $this->viewMode = 'detail';
    }

    public function showSummary(): void
    {
        $this->viewMode = 'summary';
    }

    public function showAllDetails(): void
    {
        $this->selectedSku = null;
        $this->selectedWarehouseName = null;
        $this->viewMode = 'detail';
    }

    public function selectSku(?string $sku): void
    {
        $this->selectedSku = $sku;
    }

    public function mount(): void
    {
        $defaultWh = auth()->user()?->seesAllBranches()
            ? null
            : Warehouse::where('branch_id', auth()->user()?->branch_id)->value('id');

        $this->form->fill([
            'period' => now()->format('Y-m'),
            'warehouse_id' => $defaultWh,
            'category_id' => null,
            'product_id' => null,
            'search' => null,
            'group_sales_by_day' => true,
        ]);
    }

    public function form(Form $form): Form
    {
        $periods = [];
        $cursor = now()->startOfMonth();
        for ($i = 0; $i < 12; $i++) {
            $val = $cursor->format('Y-m');
            $periods[$val] = ucfirst($cursor->translatedFormat('F Y'));
            $cursor->subMonth();
        }

        return $form->statePath('data')->columns(6)->schema([
            Forms\Components\Select::make('period')
                ->label('Periodo')
                ->options($periods)
                ->default(now()->format('Y-m'))
                ->required()
                ->live()
                ->columnSpan(2),

            Forms\Components\Select::make('warehouse_id')
                ->label('Almacén')
                ->placeholder('Todos los almacenes')
                ->options(fn () => Warehouse::where('is_active', true)->pluck('name', 'id'))
                ->live()
                ->columnSpan(2),

            Forms\Components\Select::make('category_id')
                ->label('Categoría')
                ->placeholder('Todas las categorías')
                ->options(fn () => Category::pluck('name', 'id'))
                ->live()
                ->columnSpan(2),

            Forms\Components\Select::make('product_id')
                ->label('Modelo')
                ->placeholder('Todos los modelos')
                ->searchable()
                ->options(function ($get) {
                    $q = Product::where('is_service', false);
                    if ($cat = $get('category_id')) {
                        $q->where('category_id', $cat);
                    }
                    return $q->pluck('name', 'id');
                })
                ->live()
                ->columnSpan(3),

            Forms\Components\TextInput::make('search')
                ->label('Buscador (SKU o código de barras)')
                ->placeholder('Ej: POL-NEG-S')
                ->live(debounce: 500)
                ->columnSpan(3),
        ]);
    }

    public function getReportProperty(): array
    {
        $filters = $this->data ?? [];
        if (empty($filters['period'])) {
            $filters['period'] = now()->format('Y-m');
        }

        return KardexService::getReport($filters);
    }

    /** Lista plana de todas las variantes para la tabla de inventario general. */
    public function getAllBlocksProperty(): array
    {
        $blocks = [];
        foreach ($this->report['categories'] as $catBlocks) {
            foreach ($catBlocks as $block) {
                $blocks[] = $block;
            }
        }
        return $blocks;
    }

    /** Bloques a mostrar en la vista detallada (prenda seleccionada o todas). */
    public function getDetailBlocksProperty(): array
    {
        $all = $this->allBlocks;
        if ($this->selectedSku) {
            return array_values(array_filter($all, function ($b) {
                $matchesSku = $b['sku'] === $this->selectedSku;
                if ($this->selectedWarehouseName) {
                    return $matchesSku && $b['warehouse_name'] === $this->selectedWarehouseName;
                }
                return $matchesSku;
            }));
        }
        return $all;
    }

    public function canViewCosts(): bool
    {
        $user = auth()->user();
        if (!$user) {
            return false;
        }

        return $user->seesAllBranches()
            || $user->hasRole(['super_admin', 'administrador', 'contador'])
            || $user->can('view_cost_price');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_excel')
                ->label('Exportar Excel (.xlsx)')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('success')
                ->action(fn () => KardexExcelExport::download($this->report)),

            Action::make('revalorizar')
                ->label('Revalorizar')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->visible(fn () => auth()->user()?->seesAllBranches() || auth()->user()?->hasRole(['super_admin', 'administrador']))
                ->requiresConfirmation()
                ->modalHeading('Revalorizar Kardex')
                ->modalDescription('Recalcula los costos y documentos de todos los movimientos cronológicamente. ¿Deseas continuar?')
                ->action(function () {
                    Artisan::call('kardex:revalorizar');
                    Notification::make()
                        ->title('Kardex revalorizado correctamente')
                        ->success()
                        ->send();
                }),
        ];
    }
}
