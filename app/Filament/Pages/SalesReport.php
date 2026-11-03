<?php

namespace App\Filament\Pages;

use App\Models\Branch;
use App\Services\ReportService;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Carbon\Carbon;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;

/** Ventas netas por talla, color, temporada, canal (mayorista/minorista) y modelo (Etapa 9). */
class SalesReport extends Page implements HasForms
{
    use HasPageShield;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';
    protected static ?string $navigationGroup = 'Administración';
    protected static ?string $navigationLabel = 'Reportes';
    protected static ?string $title = 'Reportes';
    protected static ?int $navigationSort = 5;
    protected static string $view = 'filament.pages.sales-report';

    public ?array $data = [];

    public const SECTIONS = [
        'size' => 'Por talla',
        'color' => 'Por color',
        'channel' => 'Mayorista / minorista',
        'reason' => 'Motivo del precio',
        'season' => 'Por temporada',
        'model' => 'Modelos más vendidos',
    ];

    public function mount(): void
    {
        $this->form->fill([
            'from' => now()->startOfMonth()->toDateString(),
            'to' => now()->toDateString(),
            'branch_id' => auth()->user()?->seesAllBranches() ? null : auth()->user()?->branch_id,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->columns(3)->schema([
            Forms\Components\DatePicker::make('from')->label('Desde')->required()->live(),
            Forms\Components\DatePicker::make('to')->label('Hasta')->required()->live(),
            Forms\Components\Select::make('branch_id')->label('Tienda')->placeholder('Todas las tiendas')
                ->options(fn () => Branch::pluck('name', 'id'))
                ->disabled(fn () => !auth()->user()?->seesAllBranches())
                ->live(),
        ]);
    }

    private function filters(): array
    {
        $branchId = auth()->user()?->seesAllBranches() ? ($this->data['branch_id'] ?? null) : auth()->user()?->branch_id;

        return [
            Carbon::parse($this->data['from'] ?? now()->startOfMonth()),
            Carbon::parse($this->data['to'] ?? now()),
            $branchId ? (int) $branchId : null,
        ];
    }

    public function getReport(): array
    {
        [$from, $to, $branchId] = $this->filters();
        $report = [];
        foreach (array_keys(self::SECTIONS) as $by) {
            $rows = ReportService::salesBy($by, $from, $to, $branchId);
            $report[$by] = $by === 'model' ? $rows->take(15)->values() : $rows;
        }

        return $report;
    }

    public function export(string $by)
    {
        abort_unless(auth()->user()?->can('export_reports'), 403);
        abort_unless(array_key_exists($by, self::SECTIONS), 404);
        [$from, $to, $branchId] = $this->filters();

        return ReportService::csv(
            "ventas-{$by}-{$from->format('Ymd')}-{$to->format('Ymd')}.csv",
            [self::SECTIONS[$by], 'Prendas', 'Monto (S/)', '% de prendas'],
            ReportService::salesBy($by, $from, $to, $branchId)->map(fn ($r) => [$r['label'], $r['units'], number_format($r['amount'], 2, '.', ''), $r['share']]),
        );
    }
}
