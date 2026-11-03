<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerPaymentResource\Pages;
use App\Models\CustomerPayment;
use App\Services\CreditService;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Historial de abonos a ventas a crédito (solo lectura; se registran desde la venta). */
class CustomerPaymentResource extends Resource
{
    protected static ?string $model = CustomerPayment::class;
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $navigationGroup = 'Ventas y cajas';
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?string $navigationLabel = 'Abonos';
    protected static ?string $modelLabel = 'Abono';
    protected static ?string $pluralModelLabel = 'Abonos';
    protected static ?int $navigationSort = 7;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('customer.name')->label('Cliente')->wrap()->searchable(),
                Tables\Columns\TextColumn::make('sale.serie')->label('Venta')
                    ->formatStateUsing(fn ($state, CustomerPayment $record) => "{$record->sale?->serie}-{$record->sale?->correlative}"),
                Tables\Columns\TextColumn::make('payment_method')->label('Medio')
                    ->formatStateUsing(fn ($state) => CreditService::METHODS[$state] ?? $state),
                Tables\Columns\TextColumn::make('amount')->label('Monto')->money('PEN'),
                Tables\Columns\TextColumn::make('reference')->label('Operación')->placeholder('—'),
                Tables\Columns\TextColumn::make('user.name')->label('Cobró'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('payment_method')->label('Medio')->options(CreditService::METHODS),
                Tables\Filters\Filter::make('fecha')
                    ->form([
                        Forms\Components\DatePicker::make('desde')->label('Desde'),
                        Forms\Components\DatePicker::make('hasta')->label('Hasta'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['desde'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['hasta'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d))),
            ])
            ->actions([])
            ->bulkActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['customer', 'sale', 'user']);
        if (!auth()->user()?->seesAllBranches()) {
            $query->where('branch_id', auth()->user()?->branch_id ?? 1);
        }

        return $query;
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCustomerPayments::route('/')];
    }
}
