<?php

namespace App\Filament\Resources\CustomerResource\RelationManagers;

use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Sale;
use App\Services\CreditService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;

class PaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';
    protected static ?string $title = 'Abonos registrados';
    protected static ?string $modelLabel = 'Abono';

    public function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public function table(Table $table): Table
    {
        /** @var Customer $customer */
        $customer = $this->getOwnerRecord();

        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('created_at', 'desc')
            ->headerActions([
                Tables\Actions\Action::make('registrar_abono')
                    ->label('Registrar abono')
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->visible(fn () => $customer->sales()->where('credit_balance', '>', 0)->exists() && (auth()->user()?->can('create_customer::payment') || auth()->user()?->can('manage_credit')))
                    ->form([
                        Forms\Components\Select::make('sale_id')
                            ->label('Venta a crédito')
                            ->options(fn () => $customer->sales()
                                ->where('credit_balance', '>', 0)
                                ->whereNotIn('sunat_status', ['anulado', 'rechazado'])
                                ->get()
                                ->mapWithKeys(fn (Sale $s) => [
                                    $s->id => "{$s->serie}-{$s->correlative} (Saldo: S/ " . number_format((float) $s->credit_balance, 2) . ")",
                                ]))
                            ->required()
                            ->live(),
                        Forms\Components\TextInput::make('amount')
                            ->label('Monto del abono')
                            ->numeric()
                            ->prefix('S/')
                            ->minValue(0.01)
                            ->required(),
                        Forms\Components\Select::make('payment_method')
                            ->label('Medio de pago')
                            ->options(CreditService::METHODS)
                            ->default('efectivo')
                            ->required(),
                        Forms\Components\TextInput::make('reference')
                            ->label('N.º de operación (opcional)')
                            ->maxLength(60),
                    ])
                    ->action(function (array $data) {
                        $sale = Sale::findOrFail($data['sale_id']);
                        try {
                            CreditService::registerPayment($sale, $data['payment_method'], (float) $data['amount'], $data['reference'] ?? null);
                        } catch (\Illuminate\Validation\ValidationException $e) {
                            Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

                            return;
                        }

                        $saldo = (float) $sale->fresh()->credit_balance;
                        Notification::make()->title('Abono registrado correctamente')
                            ->body($saldo > 0 ? 'Saldo pendiente de la venta: S/ ' . number_format($saldo, 2) : 'La venta quedó cancelada en su totalidad.')
                            ->success()->send();
                    }),
            ])
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i'),
                Tables\Columns\TextColumn::make('sale')
                    ->label('Comprobante')
                    ->formatStateUsing(fn ($record) => $record->sale ? "{$record->sale->serie}-{$record->sale->correlative}" : '—'),
                Tables\Columns\TextColumn::make('payment_method')
                    ->label('Medio de pago')
                    ->badge(),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Monto')
                    ->money('PEN'),
                Tables\Columns\TextColumn::make('reference')
                    ->label('Referencia')
                    ->default('—'),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Registrado por'),
            ]);
    }
}
