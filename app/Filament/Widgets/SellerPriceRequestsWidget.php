<?php

namespace App\Filament\Widgets;

use App\Models\WholesalePriceRequest;
use App\Services\DashboardMetrics;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Tabla "Mis solicitudes de precio": visible solo para Vendedor
 * y únicamente si tiene solicitudes pendientes o resueltas hoy.
 */
class SellerPriceRequestsWidget extends BaseWidget
{
    protected static ?string $heading = 'Mis solicitudes de precio mayorista';
    protected static ?string $pollingInterval = '30s';
    protected int | string | array $columnSpan = 'full';
    protected static ?int $sort = 3;

    public static function canView(): bool
    {
        $user = auth()->user();
        if (!$user || !$user->hasRole('vendedor') || $user->seesAllBranches()) {
            return false;
        }

        return DashboardMetrics::sellerPriceRequestsToday($user->id)->isNotEmpty();
    }

    public function table(Table $table): Table
    {
        $userId = auth()->id();

        $query = WholesalePriceRequest::query()
            ->with(['customer', 'decider'])
            ->where('requested_by', $userId)
            ->where(function ($q) {
                $q->where('status', 'pendiente')
                    ->orWhereDate('decided_at', now()->toDateString())
                    ->orWhereDate('created_at', now()->toDateString());
            })
            ->latest();

        return $table
            ->query($query)
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Hora')
                    ->dateTime('h:i A'),

                Tables\Columns\TextColumn::make('customer.name')
                    ->label('Cliente')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('items_summary')
                    ->label('Prendas')
                    ->wrap(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'aprobada' => 'success',
                        'rechazada' => 'danger',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),

                Tables\Columns\TextColumn::make('decider.name')
                    ->label('Revisado por')
                    ->default('En espera de PIN'),
            ])
            ->paginated(false);
    }
}
