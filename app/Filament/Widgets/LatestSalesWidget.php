<?php

namespace App\Filament\Widgets;

use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use App\Models\Sale;

class LatestSalesWidget extends BaseWidget
{
    // Hace que la tabla ocupe todo el ancho de la pantalla debajo de los gráficos
    protected int | string | array $columnSpan = 'full';
    protected static ?string $pollingInterval = null;

    public static function canView(): bool
    {
        return auth()->user()->seesAllBranches() || auth()->user()->can('widget_LatestSalesWidget');
    }
    // Título de la tabla
    protected static ?string $heading = 'Últimas 5 Ventas en Vivo 🔴';
    
    // Orden de aparición (4 para que vaya al final)
    protected static ?int $sort = 4;

    public function table(Table $table): Table
    {
        $user = auth()->user();
        $isSuperAdmin = $user?->seesAllBranches() ?? false;
        $branchId = $user?->branch_id ?? 1;

        $query = Sale::query()->with('branch')->latest()->limit(5);
        if (!$isSuperAdmin) {
            $query->where('branch_id', $branchId);
        }

        return $table
            ->query($query)
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Hora')
                    ->dateTime('d/m/Y h:i A')
                    ->sortable(),

                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Sucursal')
                    ->badge()
                    ->color('info')
                    ->visible($isSuperAdmin),

                Tables\Columns\TextColumn::make('serie')
                    ->label('Documento')
                    ->formatStateUsing(fn ($record) => $record->serie . '-' . $record->correlative)
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('payment_method')
                    ->label('Método de Pago')
                    ->badge()
                    ->color(fn (string $state): string => match (strtolower(trim($state))) {
                        'efectivo' => 'success',
                        'yape' => 'info',
                        'plin' => 'info',
                        'tarjeta' => 'primary',
                        'transferencia' => 'gray',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (string $state): string => ucfirst($state)),

                Tables\Columns\TextColumn::make('sunat_status')
                    ->label('SUNAT')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'aceptado' => 'success',
                        'pendiente' => 'warning',
                        'anulado' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('total')
                    ->label('Total (S/)')
                    ->money('PEN')
                    ->weight('bold')
                    ->color('success')
                    ->size(Tables\Columns\TextColumn\TextColumnSize::Large),
            ])
            ->paginated(false); // Quitamos la paginación porque solo son 5 fijas
    }
}