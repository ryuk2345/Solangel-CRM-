<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StockTransferResource\Pages;
use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\Despatch;
use App\Models\Inventory;
use App\Models\ProductVariant;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Services\SunatService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class StockTransferResource extends Resource
{
    protected static ?string $model = StockTransfer::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';
    protected static ?string $navigationGroup = '2. Catálogo e Inventario';
    protected static ?int $navigationSort = 3;
    protected static ?string $modelLabel = 'Traspaso de Stock';
    protected static ?string $pluralModelLabel = 'Traspasos de Stock';

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with([
                'originBranch',
                'originWarehouse',
                'destinationBranch',
                'destinationWarehouse',
                'sentBy',
                'receivedBy',
                'despatch',
                'items.productVariant.product',
            ]);

        $user = auth()->user();
        if ($user && !$user->seesAllBranches()) {
            $userBranchId = $user->branch_id ?? 1;
            $query->where(function (Builder $q) use ($userBranchId) {
                $q->where('origin_branch_id', $userBranchId)
                  ->orWhere('destination_branch_id', $userBranchId);
            });
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        $isSuperAdmin = auth()->user()?->seesAllBranches() ?? false;
        $userBranchId = auth()->user()?->branch_id ?? 1;

        return $form
            ->schema([
                Forms\Components\Section::make('Información del Traspaso')
                    ->schema([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                // ORIGEN
                                Forms\Components\Fieldset::make('Origen')
                                    ->schema([
                                        Forms\Components\Select::make('origin_branch_id')
                                            ->label('Tienda de Origen')
                                            ->options(Branch::where('is_active', true)->pluck('name', 'id'))
                                            ->default($userBranchId)
                                            ->disabled(!$isSuperAdmin)
                                            ->dehydrated()
                                            ->required()
                                            ->live()
                                            ->afterStateUpdated(function (Forms\Set $set, $state) {
                                                if ($state) {
                                                    $defaultWh = Warehouse::where('branch_id', $state)->where('is_default', true)->value('id')
                                                        ?? Warehouse::where('branch_id', $state)->where('is_active', true)->value('id');
                                                    $set('origin_warehouse_id', $defaultWh);
                                                }
                                            }),

                                        Forms\Components\Select::make('origin_warehouse_id')
                                            ->label('Almacén de Origen')
                                            ->options(function (Get $get) use ($userBranchId, $isSuperAdmin) {
                                                $branchId = $get('origin_branch_id') ?? ($isSuperAdmin ? null : $userBranchId);
                                                if (!$branchId) return [];
                                                return Warehouse::where('branch_id', $branchId)
                                                    ->where('is_active', true)
                                                    ->pluck('name', 'id');
                                            })
                                            ->default(function () use ($userBranchId) {
                                                return Warehouse::where('branch_id', $userBranchId)->where('is_default', true)->value('id')
                                                    ?? Warehouse::where('branch_id', $userBranchId)->where('is_active', true)->value('id');
                                            })
                                            ->required()
                                            ->live(),
                                    ])->columns(1),

                                // DESTINO
                                Forms\Components\Fieldset::make('Destino')
                                    ->schema([
                                        Forms\Components\Select::make('destination_branch_id')
                                            ->label('Tienda de Destino')
                                            ->options(Branch::where('is_active', true)->pluck('name', 'id'))
                                            ->required()
                                            ->live()
                                            ->afterStateUpdated(function (Forms\Set $set, $state) {
                                                if ($state) {
                                                    $defaultWh = Warehouse::where('branch_id', $state)->where('is_default', true)->value('id')
                                                        ?? Warehouse::where('branch_id', $state)->where('is_active', true)->value('id');
                                                    $set('destination_warehouse_id', $defaultWh);
                                                }
                                            })
                                            ->rules([
                                                fn (Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get) {
                                                    $origBranchId = $get('origin_branch_id') ?? auth()->user()?->branch_id;
                                                    if ($origBranchId && $value && (int)$origBranchId !== (int)$value) {
                                                        $origBranch = Branch::find($origBranchId);
                                                        $destBranch = Branch::find($value);
                                                        $origCode = trim($origBranch?->sunat_code ?? '');
                                                        $destCode = trim($destBranch?->sunat_code ?? '');

                                                        $isOrigValid = !empty($origCode) && ((int)$origBranch?->id === 1 || $origCode !== '0000');
                                                        $isDestValid = !empty($destCode) && ((int)$destBranch?->id === 1 || $destCode !== '0000');

                                                        if (!$isOrigValid) {
                                                            $fail("⚠️ La tienda de origen '{$origBranch?->name}' no tiene un código SUNAT válido (las sucursales secundarias requieren código distinto de 0000). Los traspasos entre tiendas exigen Guía de Remisión.");
                                                        }
                                                        if (!$isDestValid) {
                                                            $fail("⚠️ La tienda de destino '{$destBranch?->name}' no tiene un código SUNAT válido (las sucursales secundarias requieren código distinto de 0000). Los traspasos entre tiendas exigen Guía de Remisión.");
                                                        }
                                                    }
                                                },
                                            ])
                                            ->helperText(function (Get $get) {
                                                $origBranchId = $get('origin_branch_id') ?? auth()->user()?->branch_id;
                                                $destBranchId = $get('destination_branch_id');
                                                if ($origBranchId && $destBranchId && (int)$origBranchId !== (int)$destBranchId) {
                                                    return '📦 Traspaso entre tiendas: Requiere Guía de Remisión y códigos SUNAT válidos en ambas tiendas.';
                                                }
                                                if ($origBranchId && $destBranchId && (int)$origBranchId === (int)$destBranchId) {
                                                    return '🔄 Traspaso interno entre almacenes de la misma tienda.';
                                                }
                                                return null;
                                            }),

                                        Forms\Components\Select::make('destination_warehouse_id')
                                            ->label('Almacén de Destino')
                                            ->options(function (Get $get) {
                                                $branchId = $get('destination_branch_id');
                                                if (!$branchId) return [];
                                                return Warehouse::where('branch_id', $branchId)
                                                    ->where('is_active', true)
                                                    ->pluck('name', 'id');
                                            })
                                            ->required()
                                            ->live()
                                            ->rules([
                                                fn (Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get) {
                                                    $origWhId = $get('origin_warehouse_id');
                                                    if ($origWhId && $value && (int)$origWhId === (int)$value) {
                                                        $fail('El almacén de destino no puede ser idéntico al almacén de origen.');
                                                    }
                                                },
                                            ]),
                                    ])->columns(1),
                            ]),

                        Forms\Components\Textarea::make('notes')
                            ->label('Notas / Observaciones del Traspaso')
                            ->placeholder('Ej: Reabastecimiento semanal, pedido especial de cliente...')
                            ->maxLength(500)
                            ->columnSpanFull(),
                    ]),

                Forms\Components\Section::make('Productos a Traspasar')
                    ->schema([
                        Forms\Components\Repeater::make('items')
                            ->relationship('items')
                            ->addActionLabel('➕ Agregar Producto al Traspaso')
                            ->minItems(1)
                            ->defaultItems(1)
                            ->schema([
                                Forms\Components\Grid::make(12)
                                    ->schema([
                                        Forms\Components\Select::make('product_variant_id')
                                            ->label('Prenda (modelo / color / talla)')
                                            ->required()
                                            ->searchable()
                                            ->getSearchResultsUsing(function (string $search, Get $get) {
                                                $warehouseId = $get('../../origin_warehouse_id');
                                                if (!$warehouseId) return [];

                                                return Inventory::with(['productVariant.product'])
                                                    ->where('warehouse_id', $warehouseId)
                                                    ->where('stock', '>', 0)
                                                    ->whereHas('productVariant', function ($q) use ($search) {
                                                        $q->where('sku', 'like', "%{$search}%")
                                                          ->orWhereHas('product', function ($qp) use ($search) {
                                                              $qp->where('name', 'like', "%{$search}%");
                                                          });
                                                    })
                                                    ->limit(30)
                                                    ->get()
                                                    ->mapWithKeys(function ($inv) {
                                                        $variant = $inv->productVariant;
                                                        $name = $variant?->product?->name ?? 'Producto';
                                                        $sku = $variant?->sku ?? '-';
                                                        return [$variant->id => "[{$sku}] {$name} (Stock: {$inv->stock})"];
                                                    })
                                                    ->toArray();
                                            })
                                            ->getOptionLabelUsing(function ($value, Get $get) {
                                                if (!$value) return null;
                                                $variant = ProductVariant::with('product')->find($value);
                                                if (!$variant) return null;
                                                $warehouseId = $get('../../origin_warehouse_id');
                                                $stock = 0;
                                                if ($warehouseId) {
                                                    $stock = Inventory::where('product_variant_id', $value)->where('warehouse_id', $warehouseId)->value('stock') ?? 0;
                                                }
                                                return "[{$variant->sku}] {$variant->product?->name} (Stock: {$stock})";
                                            })
                                            ->columnSpan(7)
                                            ->live(),

                                        Forms\Components\TextInput::make('quantity_sent')
                                            ->label('Cantidad a Enviar')
                                            ->numeric()
                                            ->integer()
                                            ->minValue(1)
                                            ->default(1)
                                            ->required()
                                            ->rules([
                                                fn (Get $get): \Closure => function (string $attribute, $value, \Closure $fail) use ($get) {
                                                    $warehouseId = $get('../../origin_warehouse_id');
                                                    $variantId = $get('product_variant_id');
                                                    if ($warehouseId && $variantId) {
                                                        $stock = Inventory::where('product_variant_id', $variantId)
                                                            ->where('warehouse_id', $warehouseId)
                                                            ->value('stock') ?? 0;
                                                        if ((int)$value > $stock) {
                                                            $fail("Stock insuficiente en origen ({$stock} disponibles).");
                                                        }
                                                    }
                                                },
                                            ])
                                            ->columnSpan(3),

                                        Forms\Components\TextInput::make('notes')
                                            ->label('Nota de Item')
                                            ->placeholder('Opcional')
                                            ->maxLength(150)
                                            ->columnSpan(2),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        $isSuperAdmin = auth()->user()?->seesAllBranches() ?? false;

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('number')
                    ->label('Nº Traspaso')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'interno' => 'Interno (Misma Tienda)',
                        'entre_sucursales' => 'Entre Tiendas',
                        default => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        'interno' => 'gray',
                        'entre_sucursales' => 'info',
                        default => 'secondary',
                    }),

                Tables\Columns\TextColumn::make('origin_full')
                    ->label('Origen')
                    ->getStateUsing(fn (StockTransfer $record) => "{$record->originBranch?->name} ({$record->originWarehouse?->name})")
                    ->sortable(),

                Tables\Columns\TextColumn::make('destination_full')
                    ->label('Destino')
                    ->getStateUsing(fn (StockTransfer $record) => "{$record->destinationBranch?->name} ({$record->destinationWarehouse?->name})")
                    ->sortable(),

                Tables\Columns\TextColumn::make('items_count')
                    ->label('Items')
                    ->counts('items')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'en_transito' => 'En Tránsito',
                        'completado' => 'Completado',
                        'recibido_con_diferencia' => 'Con Diferencias',
                        'anulado' => 'Anulado',
                        default => $state,
                    })
                    ->color(fn ($state) => match ($state) {
                        'en_transito' => 'warning',
                        'completado' => 'success',
                        'recibido_con_diferencia' => 'danger',
                        'anulado' => 'gray',
                        default => 'secondary',
                    }),

                Tables\Columns\TextColumn::make('sentBy.name')
                    ->label('Enviado Por')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('receivedBy.name')
                    ->label('Recibido Por')
                    ->placeholder('Pendiente')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('despatch_info')
                    ->label('Guía SUNAT')
                    ->getStateUsing(function (StockTransfer $record) {
                        if ($record->type === 'interno') {
                            return 'No requerida';
                        }
                        if ($record->despatch && $record->despatch->sunat_status === 'aceptado') {
                            return "{$record->despatch->serie}-{$record->despatch->correlative}";
                        }
                        return 'Sin guía';
                    })
                    ->badge()
                    ->color(fn ($state) => match ($state) {
                        'No requerida' => 'gray',
                        'Sin guía' => 'danger',
                        default => 'success',
                    })
                    ->toggleable(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('Estado')
                    ->options([
                        'en_transito' => 'En Tránsito',
                        'completado' => 'Completado',
                        'recibido_con_diferencia' => 'Con Diferencias',
                        'anulado' => 'Anulado',
                    ]),

                Tables\Filters\SelectFilter::make('type')
                    ->label('Tipo')
                    ->options([
                        'interno' => 'Interno (Misma Tienda)',
                        'entre_sucursales' => 'Entre Tiendas',
                    ]),

                Tables\Filters\SelectFilter::make('origin_branch_id')
                    ->label('Tienda Origen')
                    ->options(Branch::pluck('name', 'id'))
                    ->visible(fn () => $isSuperAdmin),

                Tables\Filters\SelectFilter::make('destination_branch_id')
                    ->label('Tienda Destino')
                    ->options(Branch::pluck('name', 'id'))
                    ->visible(fn () => $isSuperAdmin),

                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('desde')->label('Desde'),
                        Forms\Components\DatePicker::make('hasta')->label('Hasta'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['desde'], fn ($q) => $q->whereDate('created_at', '>=', $data['desde']))
                            ->when($data['hasta'], fn ($q) => $q->whereDate('created_at', '<=', $data['hasta']));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->label('Ver')
                    ->icon('heroicon-o-eye'),

                // ACCIÓN 1: RECIBIR TRASPASO EN TIENDA DE DESTINO
                Tables\Actions\Action::make('recibir')
                    ->label('Recibir')
                    ->icon('heroicon-o-inbox-arrow-down')
                    ->color('success')
                    ->visible(function (StockTransfer $record) use ($isSuperAdmin) {
                        if ($record->status !== 'en_transito') return false;
                        if ($isSuperAdmin) return true;
                        return (int)auth()->user()?->branch_id === (int)$record->destination_branch_id;
                    })
                    ->form(function (StockTransfer $record) {
                        $fields = [];
                        foreach ($record->items as $item) {
                            $variant = $item->productVariant;
                            $name = $variant?->product?->name ?? "Variante #{$item->product_variant_id}";
                            $sku = $variant?->sku ?? '-';

                            $fields[] = Forms\Components\Fieldset::make("Item: [{$sku}] {$name}")
                                ->schema([
                                    Forms\Components\TextInput::make("items.{$item->id}.quantity_sent")
                                        ->label('Enviado')
                                        ->default($item->quantity_sent)
                                        ->disabled()
                                        ->dehydrated(false),
                                    Forms\Components\TextInput::make("items.{$item->id}.quantity_received")
                                        ->label('Recibido')
                                        ->numeric()
                                        ->integer()
                                        ->minValue(0)
                                        ->maxValue($item->quantity_sent)
                                        ->default($item->quantity_sent)
                                        ->required(),
                                    Forms\Components\TextInput::make("items.{$item->id}.notes")
                                        ->label('Observación')
                                        ->default($item->notes),
                                ])->columns(3);
                        }

                        $fields[] = Forms\Components\Textarea::make('reception_notes')
                            ->label('Observaciones de recepción')
                            ->maxLength(500);

                        return $fields;
                    })
                    ->action(function (array $data, StockTransfer $record) {
                        DB::transaction(function () use ($data, $record) {
                            $locked = StockTransfer::where('id', $record->id)->lockForUpdate()->first();
                            if ($locked->status !== 'en_transito') {
                                Notification::make()->title('Error')->body('El traspaso ya no está en tránsito.')->danger()->send();
                                return;
                            }

                            $itemsInput = $data['items'] ?? [];
                            $hasDiff = false;

                            foreach ($locked->items as $item) {
                                $itemData = $itemsInput[$item->id] ?? null;
                                $receivedQty = $itemData ? (int)$itemData['quantity_received'] : $item->quantity_sent;
                                $diff = $item->quantity_sent - $receivedQty;

                                if ($diff !== 0) $hasDiff = true;

                                $item->update([
                                    'quantity_received' => $receivedQty,
                                    'quantity_difference' => $diff,
                                    'notes' => $itemData['notes'] ?? $item->notes,
                                ]);

                                if ($receivedQty > 0) {
                                    \App\Services\StockService::move((int) $item->product_variant_id, (int) $locked->destination_warehouse_id, (int) $receivedQty, 'transfer_in', $locked, "Recepción traspaso {$locked->number}");
                                }
                            }

                            $locked->update([
                                'status' => $hasDiff ? 'recibido_con_diferencia' : 'completado',
                                'received_by_user_id' => auth()->id(),
                                'received_at' => now(),
                                'notes' => !empty($data['reception_notes'])
                                    ? ($locked->notes ? $locked->notes . ' | ' . $data['reception_notes'] : $data['reception_notes'])
                                    : $locked->notes,
                            ]);
                        });

                        Notification::make()
                            ->title('Traspaso Recibido')
                            ->body('El stock ha sido ingresado al almacén de destino.')
                            ->success()
                            ->send();
                    }),

                // ACCIÓN 2: ANULAR TRASPASO EN TRÁNSITO (SOLO SUPER_ADMIN)
                Tables\Actions\Action::make('anular')
                    ->label('Anular')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Anular Traspaso de Stock')
                    ->modalDescription(function (StockTransfer $record) {
                        $desc = 'Esta acción devolverá todo el stock de los productos al almacén de origen. Esta acción es irreversible.';
                        if ($record->despatch && $record->despatch->sunat_status === 'aceptado') {
                            $desc .= "\n\n⚠️ ATENCIÓN: Este traspaso cuenta con la Guía de Remisión {$record->despatch->serie}-{$record->despatch->correlative} aceptada por SUNAT. La anulación solo revierte el stock interno; la anulación o baja de la Guía de Remisión debe resolverse ante SUNAT por separado.";
                        }
                        return $desc;
                    })
                    ->visible(fn (StockTransfer $record) => $isSuperAdmin && $record->status === 'en_transito')
                    ->form([
                        Forms\Components\Textarea::make('cancellation_reason')
                            ->label('Motivo de la Anulación')
                            ->required()
                            ->maxLength(500),
                    ])
                    ->action(function (array $data, StockTransfer $record) {
                        DB::transaction(function () use ($data, $record) {
                            $locked = StockTransfer::where('id', $record->id)->lockForUpdate()->first();
                            if ($locked->status !== 'en_transito') {
                                Notification::make()->title('Error')->body('El traspaso ya no está en tránsito.')->danger()->send();
                                return;
                            }

                            foreach ($locked->items as $item) {
                                \App\Services\StockService::move((int) $item->product_variant_id, (int) $locked->origin_warehouse_id, (int) $item->quantity_sent, 'transfer_return', $locked, "Traspaso anulado {$locked->number}");
                            }

                            $locked->update([
                                'status' => 'anulado',
                                'cancelled_at' => now(),
                                'cancellation_reason' => $data['cancellation_reason'],
                            ]);
                        });

                        Notification::make()
                            ->title('Traspaso Anulado')
                            ->body('El traspaso fue anulado y el stock regresó al almacén de origen.')
                            ->warning()
                            ->send();
                    }),

                // ACCIÓN 3: GENERAR GUÍA DE REMISIÓN PARA TRASPASOS ENTRE TIENDAS
                Tables\Actions\Action::make('generar_guia')
                    ->label('Generar Guía')
                    ->icon('heroicon-o-truck')
                    ->color('info')
                    ->visible(fn (StockTransfer $record) => $record->type === 'entre_sucursales' && empty($record->despatch_id) && $record->status !== 'anulado')
                    ->form([
                        Forms\Components\Select::make('mode_of_transport')
                            ->label('Modalidad de Transporte')
                            ->options([
                                '02' => 'Transporte Privado',
                                '01' => 'Transporte Público',
                            ])
                            ->default('02')
                            ->required()
                            ->live(),

                        // Campos Transporte Privado
                        Forms\Components\Fieldset::make('Datos del Conductor y Vehículo')
                            ->schema([
                                Forms\Components\Select::make('driver_document_type')
                                    ->label('Tipo Doc.')
                                    ->options(['1' => 'DNI', '4' => 'Carnet Extranjería', '7' => 'Pasaporte'])
                                    ->default('1'),
                                Forms\Components\TextInput::make('driver_document_number')
                                    ->label('Nº Doc. Conductor')
                                    ->default('00000000'),
                                Forms\Components\TextInput::make('driver_name')
                                    ->label('Nombre del Conductor')
                                    ->default('CONDUCTOR GENERAL')
                                    ->required(),
                                Forms\Components\TextInput::make('driver_license_number')
                                    ->label('Nº Licencia')
                                    ->default('Q00000000'),
                                Forms\Components\TextInput::make('vehicle_plate')
                                    ->label('Placa del Vehículo')
                                    ->default('ABC123')
                                    ->required(),
                            ])
                            ->columns(3)
                            ->visible(fn (Get $get) => $get('mode_of_transport') === '02'),

                        // Campos Transporte Público
                        Forms\Components\Fieldset::make('Datos de la Empresa Transportista')
                            ->schema([
                                Forms\Components\TextInput::make('carrier_document_number')
                                    ->label('RUC Transportista')
                                    ->default('20000000001'),
                                Forms\Components\TextInput::make('carrier_name')
                                    ->label('Razón Social Transportista')
                                    ->default('EMPRESA TRANSPORTISTA SAC'),
                            ])
                            ->columns(2)
                            ->visible(fn (Get $get) => $get('mode_of_transport') === '01'),

                        Forms\Components\TextInput::make('total_weight')
                            ->label('Peso Total Estimado (KGM)')
                            ->numeric()
                            ->default(1.00)
                            ->required(),
                    ])
                    ->action(function (array $data, StockTransfer $record) {
                        try {
                            $company = CompanySetting::first();
                            $origBranch = $record->originBranch;
                            $destBranch = $record->destinationBranch;

                            $origCode = trim($origBranch?->sunat_code ?? '');
                            $destCode = trim($destBranch?->sunat_code ?? '');
                            if (empty($origCode) || $origCode === '0000' || empty($destCode) || $destCode === '0000') {
                                Notification::make()
                                    ->title('Error SUNAT')
                                    ->body('Ambas tiendas deben contar con un código de establecimiento SUNAT válido.')
                                    ->danger()
                                    ->send();
                                return;
                            }

                            // Obtener cliente (la misma empresa como destinatario interno, o el primer cliente)
                            $customerId = \App\Models\Customer::first()?->id ?? 1;

                            $despatch = DB::transaction(function () use ($data, $record, $origBranch, $destBranch, $customerId) {
                                $despatch = Despatch::create([
                                    'serie' => 'T001',
                                    'branch_id' => $record->origin_branch_id,
                                    'customer_id' => $customerId,
                                    'issue_date' => now()->format('Y-m-d'),
                                    'transfer_date' => now()->format('Y-m-d'),
                                    'transfer_reason_code' => '04', // Traslado entre establecimientos
                                    'transfer_reason_description' => 'TRASLADO ENTRE ESTABLECIMIENTOS DE LA MISMA EMPRESA',
                                    'mode_of_transport' => $data['mode_of_transport'],
                                    'total_weight' => $data['total_weight'] ?? 1.00,
                                    'unit_of_measure' => 'KGM',
                                    'number_of_packages' => $record->items->count(),
                                    'departure_ubigeo' => $origBranch?->ubigeo ?? '150101',
                                    'departure_address' => $origBranch?->address ?? 'LIMA',
                                    'arrival_ubigeo' => $destBranch?->ubigeo ?? '150101',
                                    'arrival_address' => $destBranch?->address ?? 'LIMA',
                                    'driver_document_type' => $data['driver_document_type'] ?? '1',
                                    'driver_document_number' => $data['driver_document_number'] ?? '00000000',
                                    'driver_name' => $data['driver_name'] ?? 'CONDUCTOR GENERAL',
                                    'driver_license_number' => $data['driver_license_number'] ?? 'Q00000000',
                                    'vehicle_plate' => $data['vehicle_plate'] ?? 'ABC123',
                                    'carrier_document_type' => '6',
                                    'carrier_document_number' => $data['carrier_document_number'] ?? null,
                                    'carrier_name' => $data['carrier_name'] ?? null,
                                    'observations' => "Traspaso de stock Nº {$record->number}",
                                    'sunat_status' => 'pendiente',
                                ]);

                                foreach ($record->items as $item) {
                                    $despatch->items()->create([
                                        'product_variant_id' => $item->product_variant_id,
                                        'product_name' => $item->productVariant?->product?->name ?? "Item #{$item->product_variant_id}",
                                        'quantity' => $item->quantity_sent,
                                        'unit_code' => 'NIU',
                                    ]);
                                }

                                $record->update(['despatch_id' => $despatch->id]);

                                return $despatch;
                            });

                            // Enviar a SUNAT via SunatService
                            $sunatService = new SunatService();
                            $resSunat = $sunatService->enviarGuia($despatch);

                            if (isset($resSunat['success'])) {
                                Notification::make()
                                    ->title('Guía de Remisión Generada')
                                    ->body("Se generó la guía {$despatch->serie}-{$despatch->correlative} para el traspaso.")
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('Guía generada con aviso')
                                    ->body($resSunat['error'] ?? 'Guía registrada pero pendiente de envío SUNAT.')
                                    ->warning()
                                    ->send();
                            }

                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('Error al generar Guía')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Infolists\Components\Section::make('Información del Traspaso')
                    ->schema([
                        Infolists\Components\Grid::make(4)
                            ->schema([
                                Infolists\Components\TextEntry::make('number')
                                    ->label('Nº Traspaso')
                                    ->weight('bold')
                                    ->copyable(),

                                Infolists\Components\TextEntry::make('type')
                                    ->label('Tipo de Traspaso')
                                    ->badge()
                                    ->formatStateUsing(fn ($state) => match ($state) {
                                        'interno' => 'Interno (Misma Tienda)',
                                        'entre_sucursales' => 'Entre Tiendas',
                                        default => $state,
                                    })
                                    ->color(fn ($state) => match ($state) {
                                        'interno' => 'gray',
                                        'entre_sucursales' => 'info',
                                        default => 'secondary',
                                    }),

                                Infolists\Components\TextEntry::make('status')
                                    ->label('Estado')
                                    ->badge()
                                    ->formatStateUsing(fn ($state) => match ($state) {
                                        'en_transito' => 'En Tránsito',
                                        'completado' => 'Completado',
                                        'recibido_con_diferencia' => 'Con Diferencias',
                                        'anulado' => 'Anulado',
                                        default => $state,
                                    })
                                    ->color(fn ($state) => match ($state) {
                                        'en_transito' => 'warning',
                                        'completado' => 'success',
                                        'recibido_con_diferencia' => 'danger',
                                        'anulado' => 'gray',
                                        default => 'secondary',
                                    }),

                                Infolists\Components\TextEntry::make('created_at')
                                    ->label('Fecha de Creación')
                                    ->dateTime('d/m/Y H:i'),
                            ]),

                        Infolists\Components\Grid::make(2)
                            ->schema([
                                Infolists\Components\Fieldset::make('Origen')
                                    ->schema([
                                        Infolists\Components\TextEntry::make('originBranch.name')
                                            ->label('Tienda')
                                            ->badge()
                                            ->color('info'),
                                        Infolists\Components\TextEntry::make('originWarehouse.name')
                                            ->label('Almacén')
                                            ->badge()
                                            ->color('warning'),
                                        Infolists\Components\TextEntry::make('sentBy.name')
                                            ->label('Enviado por'),
                                        Infolists\Components\TextEntry::make('sent_at')
                                            ->label('Fecha de Envío')
                                            ->dateTime('d/m/Y H:i')
                                            ->placeholder('-'),
                                    ])->columns(2),

                                Infolists\Components\Fieldset::make('Destino')
                                    ->schema([
                                        Infolists\Components\TextEntry::make('destinationBranch.name')
                                            ->label('Tienda')
                                            ->badge()
                                            ->color('info'),
                                        Infolists\Components\TextEntry::make('destinationWarehouse.name')
                                            ->label('Almacén')
                                            ->badge()
                                            ->color('warning'),
                                        Infolists\Components\TextEntry::make('receivedBy.name')
                                            ->label('Recibido por')
                                            ->placeholder('Pendiente de recepción'),
                                        Infolists\Components\TextEntry::make('received_at')
                                            ->label('Fecha de Recepción')
                                            ->dateTime('d/m/Y H:i')
                                            ->placeholder('Pendiente'),
                                    ])->columns(2),
                            ]),

                        Infolists\Components\Grid::make(2)
                            ->schema([
                                Infolists\Components\TextEntry::make('despatch_correlative')
                                    ->label('Guía de Remisión Asociada')
                                    ->getStateUsing(fn (StockTransfer $record) => $record->despatch ? "{$record->despatch->serie}-{$record->despatch->correlative}" : 'Sin Guía')
                                    ->badge()
                                    ->color(fn ($state) => $state !== 'Sin Guía' ? 'success' : 'gray'),

                                Infolists\Components\TextEntry::make('notes')
                                    ->label('Observaciones')
                                    ->placeholder('Sin observaciones'),
                            ]),

                        Infolists\Components\TextEntry::make('cancellation_reason')
                            ->label('Motivo de Anulación')
                            ->color('danger')
                            ->visible(fn (StockTransfer $record) => $record->status === 'anulado'),
                    ]),

                Infolists\Components\Section::make('Detalle de Productos Traspasados')
                    ->schema([
                        Infolists\Components\RepeatableEntry::make('items')
                            ->label('Productos')
                            ->schema([
                                Infolists\Components\TextEntry::make('productVariant.sku')
                                    ->label('SKU')
                                    ->weight('bold'),
                                Infolists\Components\TextEntry::make('productVariant.product.name')
                                    ->label('Producto'),
                                Infolists\Components\TextEntry::make('quantity_sent')
                                    ->label('Cantidad Enviada')
                                    ->badge()
                                    ->color('info'),
                                Infolists\Components\TextEntry::make('quantity_received')
                                    ->label('Cantidad Recibida')
                                    ->badge()
                                    ->color(fn ($state, $record) => $state === null ? 'gray' : ((int)$state === (int)$record->quantity_sent ? 'success' : 'danger'))
                                    ->placeholder('Pendiente'),
                                Infolists\Components\TextEntry::make('quantity_difference')
                                    ->label('Diferencia')
                                    ->badge()
                                    ->color(fn ($state) => (int)$state === 0 ? 'success' : 'danger'),
                                Infolists\Components\TextEntry::make('notes')
                                    ->label('Nota')
                                    ->placeholder('-'),
                            ])->columns(6),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStockTransfers::route('/'),
            'create' => Pages\CreateStockTransfer::route('/create'),
            'view' => Pages\ViewStockTransfer::route('/{record}'),
            'edit' => Pages\EditStockTransfer::route('/{record}/edit'),
        ];
    }
}
