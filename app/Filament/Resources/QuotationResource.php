<?php

namespace App\Filament\Resources;

use App\Filament\Resources\QuotationResource\Pages;
use App\Models\Quotation;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;

class QuotationResource extends Resource
{
    protected static ?string $model = Quotation::class;
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?string $navigationGroup = 'Ventas y cajas'; 
    protected static ?int $navigationSort = 2; 
    protected static ?string $modelLabel = 'Cotización';
    protected static ?string $pluralModelLabel = 'Cotizaciones';


    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Datos de la Cotización')
                    ->schema([
                        Forms\Components\Grid::make(12)
                            ->schema([
                                Forms\Components\Select::make('customer_id')
                                    ->label('Buscar o Crear Cliente')
                                    ->relationship('customer', 'name', modifyQueryUsing: fn ($query) => $query->limit(50))
                                    ->getOptionLabelFromRecordUsing(fn (\App\Models\Customer $record) => $record->getOptionLabel())
                                    ->getOptionLabelUsing(fn ($value) => ($c = \App\Models\Customer::find($value)) ? $c->getOptionLabel() : null)
                                    ->searchable()
                                    ->getSearchResultsUsing(fn (string $search): array => \App\Models\Customer::searchByTerm($search, 50))
                                    ->preload()
                                    ->required()
                                    ->prefixIcon('heroicon-m-user')
                                    ->columnSpan(['default' => 12, 'md' => 7])
                                    ->createOptionForm([
                                        Forms\Components\Select::make('document_type')
                                            ->label('Tipo')
                                            ->options(['1' => 'DNI', '6' => 'RUC'])
                                            ->required()
                                            ->live()
                                            ->afterStateUpdated(fn(Set $set) => $set('document_number', null)),
                                        Forms\Components\TextInput::make('document_number')
                                            ->label('Número de Documento')
                                            ->required()
                                            ->unique(table: 'customers', column: 'document_number', ignoreRecord: true)
                                            ->suffixAction(
                                                Forms\Components\Actions\Action::make('search')
                                                    ->icon('heroicon-o-magnifying-glass')
                                                    ->action(function (Set $set, Get $get) {
                                                        $tipo = $get('document_type');
                                                        $num = $get('document_number');
                                                        $token = env('API_PERU_TOKEN');
                                                        if (!$tipo || !$num) return;
                                                        try {
                                                            $url = $tipo == '1' ? "https://api.apis.net.pe/v1/dni?numero={$num}" : "https://api.apis.net.pe/v1/ruc?numero={$num}";
                                                            $res = \Illuminate\Support\Facades\Http::withToken($token)->withoutVerifying()->get($url);
                                                            if ($res->successful()) {
                                                                 $data = $res->json();
                                                                 if ($tipo == '1') {
                                                                     $set('name', trim($data['nombres'] . ' ' . $data['apellidoPaterno'] . ' ' . $data['apellidoMaterno']));
                                                                 } else {
                                                                     $set('name', $data['nombre']);
                                                                     $set('address', $data['direccion'] ?? '');
                                                                 }
                                                                 Notification::make()->title('Cliente Encontrado')->success()->send();
                                                            }
                                                        } catch (\Exception $e) {
                                                            Notification::make()->title('Error en API')->danger()->send();
                                                        }
                                                    })
                                            ),
                                        Forms\Components\TextInput::make('name')->label('Nombre / Razón Social')->required(),
                                        Forms\Components\TextInput::make('address')
                                            ->label('Dirección Fiscal')
                                            ->visible(fn(Get $get) => $get('document_type') === '6'),
                                    ]),

                                Forms\Components\Select::make('branch_id')
                                    ->label('Sucursal')
                                    ->relationship('branch', 'name')
                                    ->required()
                                    ->default(fn () => auth()->user()?->branch_id ?? 1)
                                    ->disabled(fn () => !auth()->user()?->seesAllBranches())
                                    ->dehydrated()
                                    ->live()
                                    ->prefixIcon('heroicon-m-building-storefront')
                                    ->afterStateUpdated(function (Set $set) {
                                        $set('items', []);
                                    })
                                    ->columnSpan(['default' => 12, 'md' => 4]),

                                Forms\Components\Select::make('user_id')
                                    ->label('Vendedor')
                                    ->relationship('user', 'name', function (Builder $query, Get $get) {
                                        $branchId = $get('branch_id') ?? auth()->user()?->branch_id ?? 1;
                                        return $query->where(function ($q) use ($branchId) {
                                            $q->where('branch_id', $branchId)
                                              ->orWhereHas('roles', fn($r) => $r->where('name', 'super_admin'));
                                        });
                                    })
                                    ->default(fn () => auth()->id())
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->prefixIcon('heroicon-m-user-circle')
                                    ->columnSpan(['default' => 12, 'md' => 4]),

                                Forms\Components\DateTimePicker::make('issue_date')
                                    ->label('Fecha y Hora')
                                    ->default(now())
                                    ->required()
                                    ->native(false) 
                                    ->displayFormat('d/m/Y H:i') 
                                    ->format('Y-m-d H:i:s') 
                                    ->columnSpan(['default' => 12, 'md' => 4]),
                                
                                Forms\Components\Textarea::make('notes')
                                    ->label('Observaciones / Condiciones')
                                    ->rows(2)
                                    ->columnSpan(12),
                            ]),
                    ]),

                Forms\Components\Section::make('Detalle de Prendas')
                    ->schema([
                        Forms\Components\Placeholder::make('price_mode_view')
                            ->hiddenLabel()
                            ->content(fn (Get $get) => new \Illuminate\Support\HtmlString(
                                '<div class="text-base font-bold">' . e(\App\Support\SaleCart::summary($get)) . '</div>'
                            )),
                        Forms\Components\Repeater::make('items')
                            ->hiddenLabel() 
                            ->relationship('items')
                            ->addActionLabel('➕ Agregar prenda')
                            ->defaultItems(1)
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => \App\Support\SaleCart::recalc($get, $set))
                            ->schema([
                                Forms\Components\Grid::make(12) 
                                    ->schema([
                                        Forms\Components\Select::make('product_variant_id')
                                            ->label('Prenda (modelo / color / talla) o delivery')
                                            ->searchable()
                                            ->getSearchResultsUsing(fn (string $search, Get $get) => \App\Support\SaleCart::options(
                                                $search,
                                                $get('../../branch_id') ? (int) $get('../../branch_id') : null,
                                                null,
                                                includeOutOfStock: true,
                                            ))
                                            ->getOptionLabelUsing(fn ($value, Get $get) => \App\Support\SaleCart::optionLabel(
                                                $value,
                                                \App\Support\SaleCart::warehouseFor($get('../../branch_id'), null),
                                            ))
                                            ->required()
                                            ->live()
                                            ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                                $variant = \App\Models\ProductVariant::with('product')->find($state);
                                                $set('product_name', $variant?->product?->is_service ? $variant->product->name : ($variant?->display_name ?? ''));
                                                $set('price_reason', null);
                                                \App\Support\SaleCart::recalc($get, $set, '../../');
                                            })
                                            ->columnSpan(6),

                                        Forms\Components\TextInput::make('quantity')
                                            ->label('Cant.')
                                            ->numeric()
                                            ->minValue(1)
                                            ->default(1)
                                            ->required()
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(fn (Set $set, Get $get) => \App\Support\SaleCart::recalc($get, $set, '../../'))
                                            ->columnSpan(2),

                                        Forms\Components\TextInput::make('price')
                                            ->label('Precio (S/)')
                                            ->numeric()
                                            ->required()
                                            ->readOnly(fn () => !auth()->user()?->can('edit_sale_price'))
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(function (Set $set, Get $get) {
                                                if (auth()->user()?->can('edit_sale_price')) {
                                                    $set('price_reason', 'manual');
                                                }
                                                \App\Support\SaleCart::recalc($get, $set, '../../');
                                            })
                                            ->columnSpan(2),

                                        Forms\Components\TextInput::make('total')
                                            ->label('Total (S/)') 
                                            ->readOnly()
                                            ->extraInputAttributes(['class' => 'font-bold text-right bg-gray-50'])
                                            ->columnSpan(2),

                                        Forms\Components\TextInput::make('product_name')
                                            ->label('📝 Descripción (se imprimirá en la cotización)')
                                            ->required()
                                            ->placeholder('Selecciona una prenda arriba...')
                                            ->columnSpan(12),

                                        Forms\Components\Hidden::make('igv'),
                                        Forms\Components\Hidden::make('subtotal'),
                                        Forms\Components\Hidden::make('price_type'),
                                        Forms\Components\Hidden::make('price_reason')->dehydrated(false),
                                    ]),
                            ]),
                    ]),

                Forms\Components\Grid::make(12)
                    ->schema([
                        Forms\Components\Section::make('Resumen de Cotización')
                            ->schema([
                                Forms\Components\Placeholder::make('subtotal_view')
                                    ->label('Subtotal')
                                    ->content(fn(Get $get) => 'S/ ' . number_format(collect($get('items'))->sum('total') / 1.18, 2)),

                                Forms\Components\Placeholder::make('igv_view')
                                    ->label('IGV (18%)')
                                    ->content(fn(Get $get) => 'S/ ' . number_format(collect($get('items'))->sum('total') - (collect($get('items'))->sum('total') / 1.18), 2)),

                                Forms\Components\Placeholder::make('total_view')
                                    ->label('TOTAL')
                                    ->content(fn(Get $get) => 'S/ ' . number_format(collect($get('items'))->sum('total'), 2))
                                    ->extraAttributes(['class' => 'text-3xl font-black text-primary-600 bg-primary-50 p-2 rounded-lg text-center mt-2']),
                            ])
                            ->columnSpan(['default' => 12, 'lg' => 4]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('index')
                    ->rowIndex()
                    ->label('#'),

                Tables\Columns\TextColumn::make('issue_date')
                    ->label('Fecha Emisión')
                    ->date('Y-m-d')
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Vendedor')
                    ->size('sm'),

                Tables\Columns\TextColumn::make('customer.name')
                    ->label('Cliente')
                    ->searchable(query: function (\Illuminate\Database\Eloquent\Builder $query, string $search): \Illuminate\Database\Eloquent\Builder {
                        $search = trim($search);
                        return $query->whereHas('customer', function (\Illuminate\Database\Eloquent\Builder $q) use ($search) {
                            $q->where(function (\Illuminate\Database\Eloquent\Builder $sub) use ($search) {
                                $sub->where('name', 'like', "%{$search}%")
                                    ->orWhere('document_number', 'like', "{$search}%");
                            });
                        });
                    })
                    ->wrap()
                    ->description(fn(Quotation $record): string => $record->customer?->document_number ?? '---'),

                Tables\Columns\SelectColumn::make('status')
                    ->label('Estado')
                    ->options([
                        'pendiente' => 'Registrado',
                        'aprobado' => 'Aprobado',
                        'anulado' => 'Anulado',
                    ])
                    ->selectablePlaceholder(false),

                Tables\Columns\TextColumn::make('correlative')
                    ->label('Cotización')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('sale')
                    ->label('Comprobantes')
                    ->formatStateUsing(function ($record) {
                        $sale = \App\Models\Sale::where('quotation_id', $record->id)->first();
                        return $sale ? "{$sale->serie}-{$sale->correlative}" : '';
                    })
                    ->badge()
                    ->color('success'),

                Tables\Columns\TextColumn::make('moneda')
                    ->label('Moneda')
                    ->default('PEN'),

                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Sucursal')
                    ->badge()
                    ->color('info')
                    ->sortable()
                    ->visible(fn () => auth()->user()?->seesAllBranches() ?? false),

                Tables\Columns\TextColumn::make('total')
                    ->label('Total')
                    ->money('PEN')
                    ->alignment('right')
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
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
                        'pendiente' => 'Registrado',
                        'aprobado' => 'Aprobado',
                        'anulado' => 'Anulado',
                    ]),
            ])
            ->actionsColumnLabel('Acciones')
            ->actions([
                Tables\Actions\Action::make('imprimir')
                    ->label('PDF')
                    ->icon('heroicon-o-document-text')
                    ->color('info')
                    ->url(fn(Quotation $record): string => route('quotations.pdf', $record->id))
                    ->openUrlInNewTab(),

                Tables\Actions\Action::make('aprobar_venta')
                    ->label('Aprobar y Vender')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn(Quotation $record) => $record->status === 'pendiente')
                    ->form(function (Quotation $record) {
                        return [
                            Forms\Components\Select::make('serie')
                                ->label('Tipo de Comprobante')
                                ->options(function () use ($record) {
                                    $branch = $record->branch;
                                    $sunatCode = trim($branch?->sunat_code ?? '');
                                    $isPrincipal = (int)$record->branch_id === 1;

                                    $query = \App\Models\Serie::where('is_active', true)
                                        ->where('branch_id', $record->branch_id)
                                        ->whereIn('document_type', ['00', '01', '03']);

                                    if (!$isPrincipal && (empty($sunatCode) || $sunatCode === '0000')) {
                                        $query->where('document_type', '00');
                                    }

                                    return $query->get()
                                        ->mapWithKeys(function ($serie) {
                                            $nombre = match ($serie->document_type) {
                                                '01' => 'Factura',
                                                '03' => 'Boleta',
                                                default => 'Nota de Venta',
                                            };
                                            return [$serie->serie => "{$nombre} - {$serie->serie}"];
                                        });
                                })
                                ->helperText(function () use ($record) {
                                    $branch = $record->branch;
                                    $sunatCode = trim($branch?->sunat_code ?? '');
                                    $isPrincipal = (int)$record->branch_id === 1;
                                    if (!$isPrincipal && (empty($sunatCode) || $sunatCode === '0000')) {
                                        return '⚠️ Esta sucursal no tiene registrado su código de anexo SUNAT (distinto de 0000). Solo puede emitir Notas de Venta internas.';
                                    }
                                    return null;
                                })
                                ->required()
                                ->rules(fn (Get $get): array => [
                                    function (string $attribute, $value, \Closure $fail) use ($record) {
                                        if (!str_starts_with($value, 'NV')) {
                                            $branch = $record->branch;
                                            $sunatCode = trim($branch?->sunat_code ?? '');
                                            $isPrincipal = (int)$record->branch_id === 1;
                                            if (!$isPrincipal && (empty($sunatCode) || $sunatCode === '0000')) {
                                                $fail("⚠️ La tienda '{$branch->name}' no tiene configurado un código de anexo SUNAT válido (distinto de 0000). Solo se permiten Notas de Venta.");
                                            }
                                        }

                                        $cliente = $record->customer;
                                        if (!$value || !$cliente) return;
                                        if (str_starts_with($value, 'F') && $cliente->document_type === '1') {
                                            $fail('⚠️ No puedes emitir Factura a un cliente con DNI. Selecciona Boleta o Nota de Venta.');
                                        }
                                    }
                                ]),
                            Forms\Components\Select::make('payment_condition')
                                ->label('Condición de Pago')
                                ->options([
                                    'contado' => 'Contado',
                                    'credito' => 'Crédito',
                                ])
                                ->default('contado')
                                ->required(),
                            Forms\Components\Select::make('payment_method')
                                ->label('Medio de Pago')
                                ->options([
                                    'efectivo' => '💵 Efectivo',
                                    'yape' => '🟣 Yape',
                                    'plin' => '🟢 Plin',
                                    'tarjeta' => '💳 Tarjeta/POS',
                                    'transferencia' => '🏦 Transferencia Bancaria',
                                ])
                                ->default('efectivo')
                                ->required(),
                        ];
                    })
                    ->action(function (Quotation $record, array $data) {
                        $user = Auth::user();
                        $hoyLima = now()->setTimezone('America/Lima')->startOfDay();

                        if ($user && $user->seesAllBranches()) {
                            $cajaAbierta = \App\Models\CashRegister::getActiveForSuperAdmin($record->branch_id);

                            if (!$cajaAbierta) {
                                Notification::make()
                                    ->title('Sin cajas abiertas')
                                    ->body('No hay cajas abiertas hoy en la sucursal de esta cotización. Pide al cajero que abra la caja de la tienda.')
                                    ->danger()
                                    ->send();
                                return;
                            }

                            $cajaUserId = $cajaAbierta->user_id;
                            $sellerId = $record->user_id ?: $user->id;
                        } else {
                            $cajaAbierta = \App\Models\CashRegister::where('user_id', $user->id)
                                ->where('branch_id', $record->branch_id)
                                ->where('status', 'abierta')
                                ->latest('opened_at')
                                ->first();

                            if (!$cajaAbierta) {
                                Notification::make()
                                    ->title('Caja no disponible')
                                    ->body('Debes abrir tu caja en la sucursal de esta cotización antes de poder registrar la venta.')
                                    ->danger()
                                    ->send();
                                return;
                            }

                            $fechaAperturaLima = \Carbon\Carbon::parse($cajaAbierta->opened_at)->setTimezone('America/Lima');
                            if ($fechaAperturaLima->lt($hoyLima)) {
                                $fechaFormato = $fechaAperturaLima->format('d/m/Y');
                                Notification::make()
                                    ->title('Caja de día anterior')
                                    ->body("Tu caja está abierta desde el {$fechaFormato}. Ciérrala y abre la caja de hoy para poder vender.")
                                    ->danger()
                                    ->send();
                                return;
                            }

                            $cajaUserId = $user->id;
                            $sellerId = $record->user_id ?: $user->id;
                        }

                        // Determinar document_type
                        if (str_starts_with($data['serie'], 'F')) {
                            $docType = '01'; // Factura
                        } elseif (str_starts_with($data['serie'], 'B')) {
                            $docType = '03'; // Boleta
                        } else {
                            $docType = '00'; // Nota de Venta
                        }

                        if ($docType !== '00') {
                            $tieneLineaCero = $record->items()->where(function ($q) {
                                $q->where('price', '<=', 0)->orWhere('total', '<=', 0);
                            })->exists();

                            if ($tieneLineaCero || (float)$record->total <= 0) {
                                Notification::make()
                                    ->title('Línea con precio 0 no permitida')
                                    ->body('Las boletas y facturas no admiten líneas con precio 0. Escribe la placa u observación en la descripción del producto.')
                                    ->danger()
                                    ->persistent()
                                    ->send();
                                return;
                            }
                        }

                        $warehouseParaStock = \App\Support\SaleCart::warehouseFor($record->branch_id, null);
                        $faltantes = \App\Services\StockService::shortages(
                            $record->items->map(fn ($i) => ['product_variant_id' => $i->product_variant_id, 'quantity' => $i->quantity])->all(),
                            (int) $warehouseParaStock,
                        );
                        if (!empty($faltantes)) {
                            Notification::make()->title('Stock insuficiente para vender esta cotización')
                                ->body(implode("\n", $faltantes))->danger()->persistent()->send();
                            return;
                        }

                        DB::beginTransaction();
                        try {
                            // Asignar correlativo seguro con lockForUpdate
                            $numeroAsignado = null;
                            $serieActiva = \App\Models\Serie::where('serie', $data['serie'])
                                ->where('branch_id', $record->branch_id)
                                ->where('is_active', true)
                                ->whereIn('document_type', ['00', '01', '03'])
                                ->lockForUpdate()
                                ->first();

                            if ($serieActiva) {
                                $docType = $serieActiva->document_type;
                                $numeroAsignado = $serieActiva->current_number;
                                $serieActiva->increment('current_number');
                            }

                            if (!$numeroAsignado) {
                                DB::rollBack();
                                Notification::make()
                                    ->title('Serie no disponible')
                                    ->body("No se encontró una serie activa '{$data['serie']}' para la sucursal seleccionada. No se puede emitir el comprobante sin correlativo.")
                                    ->danger()
                                    ->send();
                                return;
                            }

                            $defaultWarehouseId = \App\Models\Warehouse::where('branch_id', $record->branch_id)->where('is_default', true)->value('id')
                                ?? \App\Models\Warehouse::where('branch_id', $record->branch_id)->where('is_active', true)->value('id')
                                ?? 1;

                            // Crear Venta
                            $sale = \App\Models\Sale::create([
                                'customer_id' => $record->customer_id,
                                'user_id' => $cajaUserId,
                                'seller_id' => $sellerId,
                                'branch_id' => $record->branch_id,
                                'warehouse_id' => $defaultWarehouseId,
                                'document_type' => $docType,
                                'serie' => $data['serie'],
                                'correlative' => $numeroAsignado,
                                'issue_date' => now(),
                                'subtotal' => $record->subtotal,
                                'igv' => $record->igv,
                                'total' => $record->total,
                                'payment_condition' => $data['payment_condition'],
                                'payment_method' => $data['payment_method'],
                                'sunat_status' => 'pendiente',
                                'quotation_id' => $record->id,
                            ]);

                            // Crear detalles y actualizar stock
                            foreach ($record->items as $qItem) {
                                \App\Models\SaleItem::create([
                                    'sale_id' => $sale->id,
                                    'product_variant_id' => $qItem->product_variant_id,
                                    'product_name' => $qItem->product_name,
                                    'quantity' => $qItem->quantity,
                                    'price' => $qItem->price,
                                    'list_price' => $qItem->price,
                                    'price_type' => $qItem->price_type,
                                    'igv' => $qItem->igv,
                                    'total' => $qItem->total,
                                ]);

                                // Descontar stock por el kardex (sin stock negativo)
                                $isService = $qItem->productVariant?->product?->is_service;
                                if (!$isService) {
                                    \App\Services\StockService::move(
                                        (int) $qItem->product_variant_id,
                                        (int) $defaultWarehouseId,
                                        -1 * (int) $qItem->quantity,
                                        'sale',
                                        $sale,
                                        "Venta {$sale->serie}-{$sale->correlative} (cotización)",
                                    );
                                }
                            }

                            if ((float) $record->total < 0) {
                                throw new \Exception('El total de la cotización no puede ser negativo.');
                            }

                            // Crear el detalle de pago asegurando coincidencia total
                            $pago = \App\Models\SalePayment::create([
                                'sale_id'        => $sale->id,
                                'payment_method' => $data['payment_method'],
                                'amount'         => (float) $record->total,
                            ]);

                            // Sumar a caja exactamente el efectivo persistido en sale_payments
                            if (strtolower($pago->payment_method) === 'efectivo' && (float)$pago->amount > 0) {
                                $cajaAbierta->increment('cash_sales', (float) $pago->amount);
                            }

                            // Actualizar estado de la cotización
                            $record->update(['status' => 'aprobado']);

                            DB::commit();

                            Notification::make()
                                ->title('Venta Creada con Éxito')
                                ->success()
                                ->send();

                            // Intentar declarar a la SUNAT
                            if ($docType !== '00') {
                                try {
                                    $service = new \App\Services\SunatService();
                                    $res = $service->enviarBoleta($sale);
                                    if (isset($res['success']) && $res['success'] === true) {
                                        $sale->update([
                                            'sunat_status' => 'aceptado',
                                            'sunat_error' => null,
                                            'sunat_error_code' => null,
                                        ]);
                                        Notification::make()
                                            ->title('¡Venta Aceptada por SUNAT!')
                                            ->success()
                                            ->send();
                                    } else {
                                        $isRejected = !empty($res['rejected_by_sunat']);
                                        $sale->update([
                                            'sunat_status' => $isRejected ? 'rechazado' : 'pendiente',
                                            'sunat_error' => $res['error'] ?? null,
                                            'sunat_error_code' => $res['code'] ?? null,
                                        ]);
                                        Notification::make()
                                            ->title($isRejected ? 'Venta Rechazada por SUNAT' : 'Venta guardada. Error/Demora en SUNAT')
                                            ->body($res['error'] ?? 'Pendiente de envío manual.')
                                            ->warning()
                                            ->send();
                                    }
                                } catch (\Exception $e) {
                                    $sale->update([
                                        'sunat_status' => 'pendiente',
                                        'sunat_error' => 'Error de conexión: ' . $e->getMessage(),
                                    ]);
                                    Notification::make()
                                        ->title('Guardado local. Sin conexión a SUNAT')
                                        ->warning()
                                        ->send();
                                }
                            } else {
                                $sale->update(['sunat_status' => 'aceptado']);
                            }

                        } catch (\Exception $e) {
                            DB::rollBack();
                            Notification::make()
                                ->title('Error al aprobar cotización')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['branch', 'user', 'customer']);

        if (!auth()->user()?->seesAllBranches()) {
            $userBranchId = auth()->user()?->branch_id ?? 1;
            $query->where('branch_id', $userBranchId);
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuotations::route('/'),
            'create' => Pages\CreateQuotation::route('/create'),
        ];
    }
}
