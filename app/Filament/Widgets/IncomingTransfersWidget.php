<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\StockTransferResource;
use App\Models\StockTransfer;
use App\Services\DashboardMetrics;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

/**
 * Tabla "Traspasos por recibir": visible solo para Almacenero
 * y únicamente si hay traspasos en ruta hacia sus almacenes.
 */
class IncomingTransfersWidget extends BaseWidget
{
    protected static ?string $heading = 'Traspasos en ruta por recibir en tu tienda';
    protected static ?string $pollingInterval = '60s';
    protected int | string | array $columnSpan = 'full';
    protected static ?int $sort = 4;

    public static function canView(): bool
    {
        $user = auth()->user();
        if (!$user || !$user->hasRole('almacenero') || $user->seesAllBranches()) {
            return false;
        }

        return DashboardMetrics::incomingTransfers($user->branch_id)->isNotEmpty();
    }

    public function table(Table $table): Table
    {
        $branchId = auth()->user()?->branch_id;

        $query = StockTransfer::query()
            ->with(['originBranch', 'originWarehouse', 'items'])
            ->where('status', 'en_transito')
            ->where('destination_branch_id', $branchId)
            ->latest('sent_at');

        return $table
            ->query($query)
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('Nº Traspaso')
                    ->formatStateUsing(fn ($record) => $record->transfer_number ?: ('TR-' . str_pad($record->id, 5, '0', STR_PAD_LEFT)))
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('originBranch.name')
                    ->label('Origen')
                    ->formatStateUsing(fn ($record) => ($record->originBranch?->name ?? 'Tienda') . ' · ' . ($record->originWarehouse?->name ?? 'Almacén')),

                Tables\Columns\TextColumn::make('items_count')
                    ->label('Prendas enviadas')
                    ->state(fn ($record) => $record->items->sum('quantity_sent') . ' prendas')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('sent_at')
                    ->label('Enviado hace')
                    ->since(),
            ])
            ->actions([
                Tables\Actions\Action::make('recibir')
                    ->label('Recibir')
                    ->icon('heroicon-m-inbox-arrow-down')
                    ->color('primary')
                    ->url(fn ($record) => StockTransferResource::getUrl('view', ['record' => $record->id])),
            ])
            ->paginated(false);
    }
}
