<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CashRegisterResource\Pages;
use App\Models\CashRegister;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class CashRegisterResource extends Resource
{
    protected static ?string $model = CashRegister::class;

   protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?string $navigationGroup = '1. Ventas y Cajas';
    protected static ?int $navigationSort = 2; // <-- 2do lugar
    protected static ?string $modelLabel = 'Caja';

    /**
     * Los vendedores solo ven SU propia caja.
     * Los administradores (super_admin) ven todas.
     */
    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getEloquentQuery()->with(['user', 'branch']);
        $user = \Illuminate\Support\Facades\Auth::user();

        if (!$user->seesAllBranches()) {
            if ($user->can('view_any_cash_register')) {
                $query->where('branch_id', $user->branch_id ?? 1);
            } else {
                $query->where('user_id', $user->id);
            }
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]); // No usamos el form general, usaremos modales
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('opened_at')
                    ->label('Apertura')
                    ->dateTime('d/m/Y h:i A')
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Cajero')
                    ->searchable(),
                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Sucursal')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'abierta' => 'success',
                        'cerrada' => 'danger',
                    }),

                Tables\Columns\TextColumn::make('initial_amount')
                    ->label('Fondo Inicial')
                    ->money('PEN'),

                Tables\Columns\TextColumn::make('cash_sales')
                    ->label('Ventas (Efvo)')
                    ->money('PEN')
                    ->color('success'),

                Tables\Columns\TextColumn::make('cash_incomes')
                    ->label('Ingresos')
                    ->money('PEN')
                    ->color('success'),

                Tables\Columns\TextColumn::make('cash_expenses')
                    ->label('Egresos')
                    ->money('PEN')
                    ->color('danger'),

                // Columna dinámica que calcula cuánto debería haber AHORA
                Tables\Columns\TextColumn::make('expected_amount')
                    ->label('Total Esperado')
                    ->money('PEN')
                    ->weight('bold')
                    ->state(function (CashRegister $record): float {
                        return $record->initial_amount + $record->cash_sales + $record->cash_incomes - $record->cash_expenses;
                    }),

                Tables\Columns\TextColumn::make('declared_amount')
                    ->label('Declarado')
                    ->money('PEN')
                    ->placeholder('-'),

                Tables\Columns\TextColumn::make('difference')
                    ->label('Diferencia')
                    ->money('PEN')
                    ->placeholder('-')
                    ->badge()
                    ->color(function (CashRegister $record): string {
                        if ($record->status !== 'cerrada') return 'gray';
                        if (abs($record->difference) > 10) return 'danger';
                        if ($record->difference != 0) return 'warning';
                        return 'success';
                    })
                    ->icon(function (CashRegister $record): ?string {
                        if ($record->status === 'cerrada' && abs($record->difference) > 10 && empty(trim($record->notes ?? ''))) {
                            return 'heroicon-m-exclamation-triangle';
                        }
                        return null;
                    })
                    ->description(function (CashRegister $record): ?string {
                        if ($record->status === 'cerrada' && abs($record->difference) > 10 && empty(trim($record->notes ?? ''))) {
                            return '⚠️ Sin motivo';
                        }
                        return null;
                    }),

                Tables\Columns\TextColumn::make('notes')
                    ->label('Observaciones')
                    ->limit(20)
                    ->tooltip(fn (CashRegister $record): ?string => $record->notes)
                    ->placeholder('-'),
            ])
            ->defaultSort('opened_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('branch_id')
                    ->relationship('branch', 'name')
                    ->label('Sucursal')
                    ->searchable()
                    ->preload()
                    ->visible(fn () => auth()->user()?->seesAllBranches() ?? false),

                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        'abierta' => '🟢 Abiertas',
                        'cerrada' => '🔴 Cerradas',
                    ]),
            ])
            ->actions([
                // 🌟 BOTÓN 1: AGREGAR INGRESO (Solo si está abierta)
                Tables\Actions\Action::make('add_income')
                    ->label('Ingreso')
                    ->icon('heroicon-o-arrow-trending-up')
                    ->color('success')
                    ->button()
                    ->visible(fn(CashRegister $record) => $record->status === 'abierta')
                    ->form([
                        Forms\Components\TextInput::make('amount')
                            ->label('Monto a ingresar')
                            ->numeric()
                            ->prefix('S/')
                            ->minValue(0.01)
                            ->required(),
                        Forms\Components\TextInput::make('description')
                            ->label('¿De qué es el dinero?')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->action(function (array $data, CashRegister $record): void {
                        $record->movements()->create([
                            'user_id' => Auth::id(),
                            'type' => 'ingreso',
                            'amount' => $data['amount'],
                            'description' => $data['description'],
                        ]);
                        $record->increment('cash_incomes', $data['amount']);

                        Notification::make()
                            ->title('Ingreso registrado')
                            ->body('Se ingresaron S/ ' . number_format($data['amount'], 2) . ' a la caja.')
                            ->success()
                            ->send();
                    }),

                // 🌟 BOTÓN 2: AGREGAR EGRESO (Solo si está abierta)
                Tables\Actions\Action::make('add_expense')
                    ->label('Egreso')
                    ->icon('heroicon-o-arrow-trending-down')
                    ->color('warning')
                    ->button()
                    ->visible(fn(CashRegister $record) => $record->status === 'abierta')
                    ->form([
                        Forms\Components\TextInput::make('amount')
                            ->label('Monto a retirar')
                            ->numeric()
                            ->prefix('S/')
                            ->minValue(0.01)
                            ->required(),
                        Forms\Components\TextInput::make('description')
                            ->label('¿Para qué es el dinero?')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->action(function (array $data, CashRegister $record): void {
                        // 1. Guardar el historial del movimiento
                        $record->movements()->create([
                            'user_id' => Auth::id(),
                            'type' => 'egreso',
                            'amount' => $data['amount'],
                            'description' => $data['description'],
                        ]);
                        // 2. Descontar de la caja principal
                        $record->increment('cash_expenses', $data['amount']);

                        Notification::make()
                            ->title('Egreso registrado')
                            ->body('Se retiraron S/ ' . number_format($data['amount'], 2) . ' de la caja.')
                            ->warning()
                            ->send();
                    }),

                // 🌟 BOTÓN 3: CERRAR CAJA (Con resumen de Yape/Tarjetas)
                Tables\Actions\Action::make('close_register')
                    ->label('Cerrar Caja')
                    ->icon('heroicon-o-lock-closed')
                    ->color('danger')
                    ->button()
                    ->visible(fn(CashRegister $record) => $record->status === 'abierta')
                    
                    // 1. Calculamos cuánto se vendió por cada medio digital durante ESTE turno
                    ->mountUsing(function (Forms\ComponentContainer $form, CashRegister $record) {
                        $ventaIds = \App\Models\Sale::where('user_id', $record->user_id)
                            ->where('branch_id', $record->branch_id)
                            ->where('created_at', '>=', $record->opened_at)
                            ->whereRaw("COALESCE(sunat_status, '') != 'anulado'")
                            ->pluck('id');

                        $pagosPorMetodo = \App\Models\SalePayment::whereIn('sale_id', $ventaIds)
                            ->selectRaw('payment_method, SUM(amount) as total')
                            ->groupBy('payment_method')
                            ->pluck('total', 'payment_method');

                        $ventasConPago = \App\Models\SalePayment::whereIn('sale_id', $ventaIds)->distinct()->pluck('sale_id');
                        $queryLegacy = \App\Models\Sale::whereIn('id', $ventaIds)->whereNotIn('id', $ventasConPago);

                        $yapeVentas = (float)($pagosPorMetodo['yape'] ?? 0) + (float)(clone $queryLegacy)->where('payment_method', 'yape')->sum('total');
                        $plinVentas = (float)($pagosPorMetodo['plin'] ?? 0) + (float)(clone $queryLegacy)->where('payment_method', 'plin')->sum('total');
                        $tarjetaVentas = (float)($pagosPorMetodo['tarjeta'] ?? 0) + (float)(clone $queryLegacy)->where('payment_method', 'tarjeta')->sum('total');
                        $transfVentas = (float)($pagosPorMetodo['transferencia'] ?? 0) + (float)(clone $queryLegacy)->where('payment_method', 'transferencia')->sum('total');
                        $otrosDigital = (float)($pagosPorMetodo->except(['efectivo', 'yape', 'plin', 'tarjeta', 'transferencia'])->sum()) + (float)(clone $queryLegacy)->whereNotIn('payment_method', ['efectivo', 'yape', 'plin', 'tarjeta', 'transferencia'])->sum('total');
                        $digitalesTotal = $yapeVentas + $plinVentas + $tarjetaVentas + $transfVentas + $otrosDigital;

                        $form->fill([
                            'digital_yape' => 'S/ ' . number_format($yapeVentas, 2),
                            'digital_plin' => 'S/ ' . number_format($plinVentas, 2),
                            'digital_tarjeta' => 'S/ ' . number_format($tarjetaVentas, 2),
                            'digital_transf' => 'S/ ' . number_format($transfVentas, 2),
                            'digital_total' => 'S/ ' . number_format($digitalesTotal, 2),
                        ]);
                    })
                    
                    // 2. El Formulario del Arqueo
                    ->form([
                        Forms\Components\Section::make('Verificación de Medios Digitales')
                            ->description('Compara estos montos con tus aplicaciones (Yape/Plin/Banco) y el cierre del POS de tarjetas:')
                            ->schema([
                                Forms\Components\Placeholder::make('digital_yape')
                                    ->label('🟣 Yape')
                                    ->content(fn ($get) => $get('digital_yape') ?? 'S/ 0.00'),

                                Forms\Components\Placeholder::make('digital_plin')
                                    ->label('🟢 Plin')
                                    ->content(fn ($get) => $get('digital_plin') ?? 'S/ 0.00'),

                                Forms\Components\Placeholder::make('digital_tarjeta')
                                    ->label('💳 Tarjeta / POS')
                                    ->content(fn ($get) => $get('digital_tarjeta') ?? 'S/ 0.00'),

                                Forms\Components\Placeholder::make('digital_transf')
                                    ->label('🏦 Transferencia')
                                    ->content(fn ($get) => $get('digital_transf') ?? 'S/ 0.00'),
                            ])
                            ->columns(4),

                        Forms\Components\TextInput::make('declared_amount')
                            ->label('¿Cuánto EFECTIVO físico (Sencillo + Billetes) tienes en tu mano?')
                            ->numeric()
                            ->prefix('S/')
                            ->required(),

                        Forms\Components\Textarea::make('notes')
                            ->label('Observaciones (Opcional)')
                            ->placeholder('Ej: Faltan 2 soles porque le di vuelto de más a un cliente...'),
                    ])
                    
                    // 3. Procesar el Cierre
                    ->action(function (array $data, CashRegister $record): void {
                        // 1. Recalcular las ventas reales en efectivo del turno (excluyendo anuladas)
                        $ventaIds = \App\Models\Sale::where('user_id', $record->user_id)
                            ->where('branch_id', $record->branch_id)
                            ->where('created_at', '>=', $record->opened_at)
                            ->whereRaw("COALESCE(sunat_status, '') != 'anulado'")
                            ->pluck('id');

                        $pagosEfectivo = \App\Models\SalePayment::whereIn('sale_id', $ventaIds)
                            ->where('payment_method', 'efectivo')
                            ->sum('amount');

                        $ventasConPago = \App\Models\SalePayment::whereIn('sale_id', $ventaIds)->distinct()->pluck('sale_id');
                        
                        $legacyEfectivo = \App\Models\Sale::whereIn('id', $ventaIds)
                            ->whereNotIn('id', $ventasConPago)
                            ->where('payment_method', 'efectivo')
                            ->sum('total');

                        $cashSalesReal = $pagosEfectivo + $legacyEfectivo;

                        // Calculamos la matemática del EFECTIVO exclusivamente en el servidor
                        $expected = $record->initial_amount + $cashSalesReal + $record->cash_incomes - $record->cash_expenses;
                        $difference = $data['declared_amount'] - $expected;

                        // Cerramos la caja y guardamos el valor real recalculado
                        $record->update([
                            'cash_sales' => $cashSalesReal,
                            'status' => 'cerrada',
                            'closed_at' => now(),
                            'expected_amount' => $expected,
                            'declared_amount' => $data['declared_amount'],
                            'difference' => $difference,
                            'notes' => $data['notes'],
                        ]);

                        // Notificación si la diferencia supera S/ 10 en cualquier sentido
                        if (abs($difference) > 10) {
                            $montoTexto = 'S/ ' . number_format(abs($difference), 2);
                            if ($difference > 0) {
                                Notification::make()
                                    ->title('Caja cerrada con SOBRANTE de ' . $montoTexto)
                                    ->body('Existe un sobrante superior a S/ 10. Por favor, registra el motivo en la columna de observaciones o usando el botón "Registrar motivo".')
                                    ->warning()
                                    ->persistent()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('Caja cerrada con FALTANTE de ' . $montoTexto)
                                    ->body('Existe un faltante superior a S/ 10. Por favor, registra el motivo en la columna de observaciones o usando el botón "Registrar motivo".')
                                    ->danger()
                                    ->persistent()
                                    ->send();
                            }
                        } else {
                            Notification::make()
                                ->title('Caja cerrada correctamente')
                                ->body('Diferencia de efectivo: S/ ' . number_format($difference, 2))
                                ->success()
                                ->send();
                        }
                    })
                    ->modalHeading('Arqueo Final de Caja')
                    ->modalDescription('Cuenta tu efectivo físico e ingrésalo aquí. Los montos digitales ya quedaron registrados.'),

                // 🌟 BOTÓN 4: REGISTRAR MOTIVO (Solo si está cerrada con descuadre > S/ 10)
                Tables\Actions\Action::make('registrar_motivo')
                    ->label('Registrar motivo')
                    ->icon('heroicon-o-chat-bubble-bottom-center-text')
                    ->color('warning')
                    ->button()
                    ->visible(function (CashRegister $record): bool {
                        if ($record->status !== 'cerrada' || abs($record->difference) <= 10) {
                            return false;
                        }
                        $user = Auth::user();
                        if (!$user) return false;
                        return $user->id === $record->user_id || $user->seesAllBranches();
                    })
                    ->form([
                        Forms\Components\Placeholder::make('info_diferencia')
                            ->label('Diferencia detectada')
                            ->content(fn (CashRegister $record) => ($record->difference > 0 ? 'SOBRANTE de S/ ' : 'FALTANTE de S/ ') . number_format(abs($record->difference), 2)),
                        Forms\Components\Textarea::make('notes')
                            ->label('Motivo / Justificación de la diferencia')
                            ->required()
                            ->default(fn (CashRegister $record) => $record->notes)
                            ->placeholder('Explica qué causó este faltante o sobrante...')
                            ->rows(4),
                    ])
                    ->action(function (array $data, CashRegister $record): void {
                        $record->update([
                            'notes' => $data['notes'],
                        ]);
                        Notification::make()
                            ->title('Motivo registrado correctamente')
                            ->success()
                            ->send();
                    }),

                // 🌟 BOTÓN 5: IMPRIMIR REPORTE DE CIERRE (Solo si está cerrada)
                Tables\Actions\Action::make('imprimir_cierre')
                    ->label('Imprimir PDF')
                    ->icon('heroicon-o-printer')
                    ->color('gray')
                    ->url(fn (CashRegister $record): string => route('caja.imprimir_cierre', $record->id))
                    ->openUrlInNewTab()
                    ->visible(fn (CashRegister $record) => $record->status === 'cerrada'),
            ])
            
            ->headerActions([
                // 🌟 ACCESO RÁPIDO: INGRESO DE CAJA ABIERTA
                Tables\Actions\Action::make('header_add_income')
                    ->label('➕ Ingreso')
                    ->icon('heroicon-o-arrow-trending-up')
                    ->color('success')
                    ->visible(function () {
                        return CashRegister::where('user_id', Auth::id())->where('status', 'abierta')->exists();
                    })
                    ->form([
                        Forms\Components\TextInput::make('amount')
                            ->label('Monto a ingresar')
                            ->numeric()
                            ->prefix('S/')
                            ->minValue(0.01)
                            ->required(),
                        Forms\Components\TextInput::make('description')
                            ->label('¿De qué es el dinero?')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->action(function (array $data): void {
                        $caja = CashRegister::where('user_id', Auth::id())->where('status', 'abierta')->first();
                        if (!$caja) return;

                        $caja->movements()->create([
                            'user_id' => Auth::id(),
                            'type' => 'ingreso',
                            'amount' => $data['amount'],
                            'description' => $data['description'],
                        ]);
                        $caja->increment('cash_incomes', $data['amount']);

                        Notification::make()
                            ->title('Ingreso registrado')
                            ->body('Se ingresaron S/ ' . number_format($data['amount'], 2) . ' a tu caja.')
                            ->success()
                            ->send();
                    }),

                // 🌟 ACCESO RÁPIDO: EGRESO DE CAJA ABIERTA
                Tables\Actions\Action::make('header_add_expense')
                    ->label('➖ Egreso')
                    ->icon('heroicon-o-arrow-trending-down')
                    ->color('warning')
                    ->visible(function () {
                        return CashRegister::where('user_id', Auth::id())->where('status', 'abierta')->exists();
                    })
                    ->form([
                        Forms\Components\TextInput::make('amount')
                            ->label('Monto a retirar')
                            ->numeric()
                            ->prefix('S/')
                            ->minValue(0.01)
                            ->required(),
                        Forms\Components\TextInput::make('description')
                            ->label('¿Para qué es el dinero?')
                            ->required()
                            ->maxLength(255),
                    ])
                    ->action(function (array $data): void {
                        $caja = CashRegister::where('user_id', Auth::id())->where('status', 'abierta')->first();
                        if (!$caja) return;

                        $caja->movements()->create([
                            'user_id' => Auth::id(),
                            'type' => 'egreso',
                            'amount' => $data['amount'],
                            'description' => $data['description'],
                        ]);
                        $caja->increment('cash_expenses', $data['amount']);

                        Notification::make()
                            ->title('Egreso registrado')
                            ->body('Se retiraron S/ ' . number_format($data['amount'], 2) . ' de tu caja.')
                            ->warning()
                            ->send();
                    }),

                // 🌟 BOTÓN: ABRIR CAJA NUEVA (Solo si no tiene caja abierta)
                Tables\Actions\Action::make('open_register')
                    ->label('Abrir Caja')
                    ->icon('heroicon-o-key')
                    ->color('success')
                    ->visible(function () {
                        return !CashRegister::where('user_id', Auth::id())->where('status', 'abierta')->exists();
                    })
                    ->form([
                        Forms\Components\Select::make('branch_id')
                            ->label('¿En qué Sucursal estás abriendo la caja?')
                            ->options(function () {
                                if (Auth::user()->seesAllBranches()) {
                                    return \App\Models\Branch::where('is_active', true)->pluck('name', 'id');
                                }
                                return \App\Models\Branch::where('id', Auth::user()->branch_id ?? 1)->pluck('name', 'id');
                            })
                            ->required()
                            ->default(fn () => Auth::user()->branch_id ?? 1)
                            ->disabled(fn () => !Auth::user()->seesAllBranches())
                            ->dehydrated()
                            ->live()
                            ->helperText(function (Forms\Get $get) {
                                if (!Auth::user()?->seesAllBranches()) {
                                    return null;
                                }
                                $branchId = $get('branch_id');
                                if (!$branchId) return null;

                                $hoyLima = now()->setTimezone('America/Lima')->startOfDay();
                                $cajaExistente = CashRegister::where('branch_id', $branchId)
                                    ->where('status', 'abierta')
                                    ->where('opened_at', '>=', $hoyLima)
                                    ->with('user')
                                    ->first();

                                if ($cajaExistente) {
                                    $cajero = $cajaExistente->user?->name ?? 'Cajero';
                                    return "⚠️ Ya hay una caja abierta hoy en esta tienda ({$cajero}). Tus ventas se registran en esa caja; no necesitas abrir otra.";
                                }
                                return null;
                            })
                            ->rules([
                                function () {
                                    return function (string $attribute, $value, \Closure $fail) {
                                        if (!Auth::user()?->seesAllBranches()) {
                                            return;
                                        }
                                        $hoyLima = now()->setTimezone('America/Lima')->startOfDay();
                                        $cajaExistente = CashRegister::where('branch_id', $value)
                                            ->where('status', 'abierta')
                                            ->where('opened_at', '>=', $hoyLima)
                                            ->with('user')
                                            ->first();

                                        if ($cajaExistente) {
                                            $cajero = $cajaExistente->user?->name ?? 'Cajero';
                                            $fail("Ya hay una caja abierta hoy en esta tienda ({$cajero}). Tus ventas se registran en esa caja; no necesitas abrir otra.");
                                        }
                                    };
                                }
                            ]),

                        Forms\Components\TextInput::make('initial_amount')
                            ->label('Sencillo en Caja (Monto Inicial)')
                            ->numeric()
                            ->default(0)
                            ->prefix('S/')
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $hoyLima = now()->setTimezone('America/Lima')->startOfDay();

                        if (Auth::user()?->seesAllBranches()) {
                            $cajaExistente = CashRegister::where('branch_id', $data['branch_id'])
                                ->where('status', 'abierta')
                                ->where('opened_at', '>=', $hoyLima)
                                ->with('user')
                                ->first();

                            if ($cajaExistente) {
                                $cajero = $cajaExistente->user?->name ?? 'Cajero';
                                Notification::make()
                                    ->title('Caja ya abierta')
                                    ->body("Ya hay una caja abierta hoy en esta tienda ({$cajero}). Tus ventas se registran en esa caja; no necesitas abrir otra.")
                                    ->warning()
                                    ->send();
                                return;
                            }
                        }

                        CashRegister::create([
                            'user_id' => Auth::id(),
                            'branch_id' => $data['branch_id'],
                            'status' => 'abierta',
                            'opened_at' => now(),
                            'initial_amount' => $data['initial_amount'],
                        ]);

                        Notification::make()
                            ->title('Caja abierta')
                            ->body('Caja iniciada con S/ ' . number_format($data['initial_amount'], 2))
                            ->success()
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageCashRegisters::route('/'),
        ];
    }
}