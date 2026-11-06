<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SaleReturnResource\Pages;
use App\Models\SaleReturn;
use App\Services\SaleExchangeService;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Cambios de talla/color y devoluciones, con su nota de crédito parcial (Etapa 8). */
class SaleReturnResource extends Resource
{
    protected static ?string $model = SaleReturn::class;
    protected static ?string $navigationGroup = 'Ventas y cajas';
    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';
    protected static ?string $navigationLabel = 'Cambios y devoluciones';
    protected static ?string $modelLabel = 'Cambio o devolución';
    protected static ?string $pluralModelLabel = 'Cambios y devoluciones';
    protected static ?int $navigationSort = 5;

    public static function canCreate(): bool
    {
        return false; // Se registran desde la venta: "Cambio / devolución"
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = static::getEloquentQuery()->whereIn('sunat_status', ['pendiente', 'rechazado'])->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('type')->label('Tipo')->badge()
                    ->formatStateUsing(fn ($state) => $state === 'cambio' ? 'Cambio' : 'Devolución')
                    ->color(fn ($state) => $state === 'cambio' ? 'info' : 'warning'),
                Tables\Columns\TextColumn::make('sale.serie')->label('Venta original')
                    ->formatStateUsing(fn ($state, SaleReturn $record) => "{$record->sale?->serie}-{$record->sale?->correlative}")
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('sale', fn ($s) => $s->where('serie', 'like', "%{$search}%")->orWhere('correlative', $search))),
                Tables\Columns\TextColumn::make('sale.customer.name')->label('Cliente')->wrap(),
                Tables\Columns\TextColumn::make('items_summary')->label('Prendas devueltas')->wrap()
                    ->state(fn (SaleReturn $record) => $record->items->map(fn ($i) => "{$i->quantity} × {$i->product_name}")->implode(', ')),
                Tables\Columns\TextColumn::make('total')->label('Valor')->money('PEN'),
                Tables\Columns\TextColumn::make('credit_note_number')->label('Nota de crédito')->placeholder('—'),
                Tables\Columns\TextColumn::make('sunat_status')->label('SUNAT')->badge()
                    ->formatStateUsing(fn ($state) => $state === 'interna' ? 'No aplica (NV)' : ucfirst($state))
                    ->color(fn ($state) => match ($state) {
                        'aceptado' => 'success',
                        'interna' => 'gray',
                        'rechazado' => 'danger',
                        default => 'warning',
                    })
                    ->description(fn (SaleReturn $record) => in_array($record->sunat_status, ['pendiente', 'rechazado'], true) ? str($record->sunat_error)->limit(80) : null),
                Tables\Columns\TextColumn::make('newSale.serie')->label('Venta del cambio')
                    ->formatStateUsing(fn ($state, SaleReturn $record) => $record->newSale ? "{$record->newSale->serie}-{$record->newSale->correlative}" : null)
                    ->placeholder(fn (SaleReturn $record) => $record->type === 'cambio' ? 'Pendiente' : '—'),
                Tables\Columns\TextColumn::make('user.name')->label('Registró')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')->label('Tipo')->options(['cambio' => 'Cambio', 'devolucion' => 'Devolución']),
                Tables\Filters\SelectFilter::make('sunat_status')->label('SUNAT')
                    ->options(['pendiente' => 'Pendiente', 'aceptado' => 'Aceptado', 'rechazado' => 'Rechazado', 'interna' => 'No aplica']),
            ])
            ->actions([
                Tables\Actions\Action::make('completar')
                    ->label('Registrar prenda nueva')
                    ->icon('heroicon-o-shopping-cart')
                    ->visible(fn (SaleReturn $record) => $record->type === 'cambio' && $record->new_sale_id === null)
                    ->url(fn (SaleReturn $record) => SaleResource::getUrl('create', ['cambio' => $record->id])),
                Tables\Actions\Action::make('reintentar')
                    ->label('Reenviar a SUNAT')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (SaleReturn $record) => in_array($record->sunat_status, ['pendiente', 'rechazado'], true))
                    ->requiresConfirmation()
                    ->action(function (SaleReturn $record) {
                        $record = SaleExchangeService::sendCreditNote($record);
                        Notification::make()
                            ->title($record->sunat_status === 'aceptado' ? "Nota de crédito {$record->credit_note_number} aceptada" : 'SUNAT no aceptó la nota de crédito')
                            ->body($record->sunat_status === 'aceptado' ? null : $record->sunat_error)
                            ->{$record->sunat_status === 'aceptado' ? 'success' : 'danger'}()
                            ->send();
                    }),
                Tables\Actions\Action::make('imprimir')
                    ->label('Imprimir')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->url(fn (SaleReturn $record) => route('sale-returns.ticket', $record))
                    ->openUrlInNewTab(),
            ])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['sale.customer', 'newSale', 'items', 'user']);
        if (!auth()->user()?->seesAllBranches()) {
            $query->where('branch_id', auth()->user()?->branch_id ?? 1);
        }

        return $query;
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListSaleReturns::route('/')];
    }
}
