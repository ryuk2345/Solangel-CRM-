<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Filament\Resources\SaleResource;
use App\Models\Sale;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class SalesRelationManager extends RelationManager
{
    protected static string $relationship = 'sales';
    protected static ?string $title = 'Compras del cliente';
    protected static ?string $modelLabel = 'Venta';

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('serie')
            ->defaultSort('created_at', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i'),
                Tables\Columns\TextColumn::make('serie_correlative')
                    ->label('Comprobante')
                    ->state(fn (Sale $record) => "{$record->serie}-{$record->correlative}")
                    ->searchable(['serie', 'correlative']),
                Tables\Columns\TextColumn::make('payment_condition')
                    ->label('Condición')
                    ->badge()
                    ->formatStateUsing(fn ($state) => ucfirst($state ?: 'contado'))
                    ->color(fn ($state) => $state === 'credito' ? 'warning' : 'success'),
                Tables\Columns\TextColumn::make('total')
                    ->label('Total')
                    ->money('PEN'),
                Tables\Columns\TextColumn::make('credit_balance')
                    ->label('Saldo pendiente')
                    ->money('PEN')
                    ->color(fn ($state) => (float) $state > 0 ? 'danger' : 'gray'),
                Tables\Columns\TextColumn::make('sunat_status')
                    ->label('SUNAT')
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'aceptado' => 'success',
                        'rechazado', 'anulado' => 'danger',
                        default => 'warning',
                    }),
            ])
            ->actions([
                SaleResource::abonoAction(Tables\Actions\Action::make('registrar_abono')),
                Tables\Actions\Action::make('view')
                    ->label('Ver')
                    ->icon('heroicon-o-eye')
                    ->url(fn (Sale $record) => SaleResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
