<?php

namespace App\Filament\Pages;

use App\Models\ProductVariant;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/** Imprimir etiquetas de cualquier prenda (reposición, cambio de precio, etiqueta dañada). */
class PrintLabels extends Page implements HasForms
{
    use HasPageShield;
    use InteractsWithForms;

    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $navigationIcon = 'heroicon-o-qr-code';
    protected static ?string $navigationGroup = '2. Catálogo e Inventario';
    protected static ?string $navigationLabel = 'Etiquetas';
    protected static ?string $title = 'Imprimir etiquetas';
    protected static ?int $navigationSort = 8;
    protected static string $view = 'filament.pages.print-labels';

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(['lines' => [['quantity' => 1]]]);
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Forms\Components\Repeater::make('lines')
                ->label('Prendas')
                ->addActionLabel('Agregar prenda')
                ->columns(6)
                ->schema([
                    Forms\Components\Select::make('product_variant_id')
                        ->label('Prenda')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => ProductVariant::with('product')
                            ->whereHas('product', fn ($p) => $p->where('is_service', false))
                            ->where(fn ($q) => $q->where('barcode', $search)->orWhere('sku', 'like', "%{$search}%")
                                ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$search}%")))
                            ->limit(50)->get()->mapWithKeys(fn ($v) => [$v->id => "[{$v->sku}] {$v->display_name}"]))
                        ->getOptionLabelUsing(fn ($value) => ($x = ProductVariant::with('product')->find($value)) ? "[{$x->sku}] {$x->display_name}" : null)
                        ->required()
                        ->columnSpan(5),
                    Forms\Components\TextInput::make('quantity')->label('Cantidad')->numeric()->minValue(1)->maxValue(500)->default(1)->required(),
                ]),
        ]);
    }

    public function generate(): void
    {
        $lines = collect($this->form->getState()['lines'] ?? [])
            ->filter(fn ($l) => !empty($l['product_variant_id']) && (int) $l['quantity'] > 0);

        if ($lines->isEmpty()) {
            Notification::make()->title('Agrega al menos una prenda')->warning()->send();

            return;
        }

        $items = $lines->map(fn ($l) => (int) $l['product_variant_id'] . ':' . (int) $l['quantity'])->implode(',');
        $this->js('window.open(' . json_encode(route('labels.custom', ['items' => $items])) . ', "_blank")');
    }
}
