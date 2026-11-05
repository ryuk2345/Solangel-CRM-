<?php

namespace App\Filament\Widgets;

use App\Models\Sale;
use App\Services\DashboardMetrics;
use Carbon\Carbon;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Fila condicional: Últimas 5 ventas del día en vivo (polling 60s).
 * Se oculta automáticamente si no hay ventas hoy o para el rol almacenero.
 */
class LatestSalesWidget extends BaseWidget
{
    use InteractsWithPageFilters;

    protected int | string | array $columnSpan = 'full';
    protected static ?string $heading = 'Últimas 5 ventas de hoy';
    protected static ?string $pollingInterval = '60s';

    public static function canView(): bool
    {
        $user = auth()->user();
        if (!$user || $user->hasRole('almacenero')) {
            return false;
        }

        $branchId = !$user->seesAllBranches() ? ($user->branch_id ?? 1) : null;
        return DashboardMetrics::todayHasSales($branchId);
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

        $query = Sale::query()
            ->with(['customer', 'items'])
            ->whereNotIn('sunat_status', ['anulado', 'rechazado'])
            ->whereDate('issue_date', Carbon::today())
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->latest('created_at')
            ->limit(5);

        return $table
            ->query($query)
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Hora')
                    ->dateTime('h:i A'),

                Tables\Columns\TextColumn::make('serie')
                    ->label('Comprobante')
                    ->formatStateUsing(fn ($record) => $record->serie . '-' . $record->correlative)
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('customer.name')
                    ->label('Cliente')
                    ->default('Cliente general')
                    ->wrap(),

                Tables\Columns\TextColumn::make('tipo_precio')
                    ->label('Tipo de precio')
                    ->badge()
                    ->state(fn ($record) => $record->items->contains(fn ($i) => $i->price_type === 'wholesale') ? 'Mayorista' : 'Minorista')
                    ->color(fn (string $state): string => $state === 'Mayorista' ? 'success' : 'primary'),

                Tables\Columns\TextColumn::make('total')
                    ->label('Total')
                    ->money('PEN')
                    ->weight('bold')
                    ->alignEnd(),
            ])
            ->paginated(false);
    }
}
