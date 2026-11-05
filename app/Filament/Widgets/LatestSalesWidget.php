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
 * Tabla compacta "Mis últimas ventas de hoy".
 * Visible únicamente si existen ventas registradas hoy.
 */
class LatestSalesWidget extends BaseWidget
{
    use InteractsWithPageFilters;

    protected int | string | array $columnSpan = 'full';
    protected static ?string $pollingInterval = '60s';
    protected static ?int $sort = 5;

    public static function canView(): bool
    {
        $user = auth()->user();
        if (!$user || $user->hasRole(['almacenero', 'contador'])) {
            return false;
        }

        $userId = $user->hasRole('vendedor') && !$user->seesAllBranches() ? $user->id : null;
        $branchId = !$user->seesAllBranches() ? ($user->branch_id ?? 1) : null;

        return DashboardMetrics::todaySales($branchId, $userId)['count'] > 0;
    }

    public function getTableHeading(): ?string
    {
        return auth()->user()?->hasRole('vendedor') && !auth()->user()?->seesAllBranches()
            ? 'Mis últimas ventas de hoy'
            : 'Últimas 5 ventas de hoy';
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
        $user = auth()->user();
        $isSeller = $user?->hasRole('vendedor') && !$user?->seesAllBranches();
        $branchId = $this->getBranchId();

        $query = Sale::query()
            ->with(['customer', 'items'])
            ->whereNotIn('sunat_status', ['anulado', 'rechazado'])
            ->whereDate('issue_date', Carbon::today())
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($isSeller, fn ($q) => $q->where('user_id', $user?->id))
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
                    ->formatStateUsing(fn ($state) => 'S/ ' . number_format((float) $state, 2))
                    ->weight('bold')
                    ->alignEnd(),
            ])
            ->paginated(false);
    }
}
