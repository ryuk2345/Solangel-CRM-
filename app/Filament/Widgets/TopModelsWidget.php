<?php

namespace App\Filament\Widgets;

use App\Models\Product;
use Carbon\Carbon;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Fila 4 (Columna 2): Top 5 modelos más vendidos del mes (unidades, S/ y talla más vendida).
 * Solo visible para Administrador y Contador. Sin polling.
 */
class TopModelsWidget extends BaseWidget
{
    use InteractsWithPageFilters;

    protected static ?string $heading = 'Top 5 modelos del mes';
    protected static ?string $pollingInterval = null;

    protected int | string | array $columnSpan = [
        'default' => 'full',
        'md' => 1,
    ];

    public static function canView(): bool
    {
        $user = auth()->user();
        if (!$user) {
            return false;
        }

        return $user->seesAllBranches()
            || $user->hasRole(['super_admin', 'administrador', 'contador']);
    }

    protected function getBranchId(): ?int
    {
        $user = auth()->user();
        if (!$user?->seesAllBranches()) {
            return $user?->branch_id ?? 1;
        }

        return !empty($this->filters['branch_id']) ? (int) $this->filters['branch_id'] : null;
    }

    public function table(Table $table): Table
    {
        $branchId = $this->getBranchId();
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth = Carbon::now()->endOfMonth();

        $query = Product::query()
            ->from('products as p')
            ->where('p.is_service', false)
            ->join('product_variants as pv', 'pv.product_id', '=', 'p.id')
            ->join('sale_items as si', 'si.product_variant_id', '=', 'pv.id')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->whereNotIn('s.sunat_status', ['anulado', 'rechazado'])
            ->whereBetween('s.issue_date', [$startOfMonth, $endOfMonth])
            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
            ->groupBy('p.id', 'p.name')
            ->selectRaw('
                p.id,
                p.name as product_name,
                SUM(si.quantity) as units,
                SUM(si.total) as amount
            ')
            ->orderByDesc(DB::raw('SUM(si.quantity)'))
            ->limit(5);

        return $table
            ->query($query)
            ->columns([
                Tables\Columns\TextColumn::make('product_name')
                    ->label('Modelo')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('units')
                    ->label('Unidades')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Total')
                    ->money('PEN')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('top_size')
                    ->label('Talla top')
                    ->badge()
                    ->color('info')
                    ->alignCenter()
                    ->state(function ($record) use ($branchId, $startOfMonth, $endOfMonth) {
                        return DB::table('sale_items as si')
                            ->join('sales as s', 's.id', '=', 'si.sale_id')
                            ->join('product_variants as pv', 'pv.id', '=', 'si.product_variant_id')
                            ->where('pv.product_id', $record->id)
                            ->whereNotIn('s.sunat_status', ['anulado', 'rechazado'])
                            ->whereBetween('s.issue_date', [$startOfMonth, $endOfMonth])
                            ->when($branchId, fn ($q) => $q->where('s.branch_id', $branchId))
                            ->selectRaw("COALESCE(pv.size, 'Única') as size_name, SUM(si.quantity) as qty")
                            ->groupBy('size_name')
                            ->orderByDesc('qty')
                            ->value('size_name') ?: '—';
                    }),
            ])
            ->paginated(false)
            ->emptyStateHeading('Sin ventas registradas este mes');
    }
}
