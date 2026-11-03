<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DespatchResource\Pages;
use App\Models\Despatch;
use App\Models\Sale;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\ProductVariant;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Infolists\Infolist;
use Filament\Infolists\Components\Section as InfoSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class DespatchResource extends Resource
{
    protected static ?string $model = Despatch::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';
    protected static ?string $navigationGroup = 'Administración';
    protected static ?int $navigationSort = 2;
    protected static ?string $modelLabel = 'Guía de Remisión';
    protected static ?string $pluralModelLabel = 'Guías de Remisión';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Asociación y Documento')
                    ->schema([
                        Forms\Components\Select::make('sale_id')
                            ->label('Asociar con Venta (Opcional)')
                            ->options(
                                Sale::orderBy('created_at', 'desc')
                                    ->limit(100)
                                    ->get()
                                    ->mapWithKeys(fn ($sale) => [$sale->id => "{$sale->serie}-{$sale->correlative} (Total: S/ {$sale->total})"])
                            )
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                if ($state) {
                                    $sale = Sale::with(['items', 'customer', 'branch'])->find($state);
                                    if ($sale) {
                                        $set('customer_id', $sale->customer_id);
                                        $set('branch_id', $sale->branch_id);
                                        
                                        if ($sale->branch) {
                                            $set('departure_address', $sale->branch->address);
                                            $set('departure_ubigeo', $sale->branch->ubigeo);
                                        }
                                        
                                        if ($sale->customer) {
                                            $set('arrival_address', $sale->customer->address);
                                            $set('arrival_ubigeo', $sale->customer->ubigeo ?? '150101');
                                        }

                                        // Cargar items de la venta
                                        $items = [];
                                        foreach ($sale->items as $item) {
                                            $items[] = [
                                                'product_variant_id' => $item->product_variant_id,
                                                'product_name' => $item->product_name,
                                                'quantity' => $item->quantity,
                                                'unit_code' => 'NIU',
                                            ];
                                        }
                                        $set('items', $items);
                                    }
                                }
                            }),

                        Forms\Components\Select::make('branch_id')
                            ->label('Establecimiento de Partida')
                            ->relationship('branch', 'name')
                            ->required()
                            ->default(fn () => auth()->user()?->branch_id ?? 1)
                            ->disabled(fn () => !auth()->user()?->seesAllBranches())
                            ->dehydrated()
                            ->live()
                            ->rules(fn (Forms\Get $get): array => [
                                function (string $attribute, $value, \Closure $fail) {
                                    if (!$value) return;
                                    $branch = \App\Models\Branch::find($value);
                                    $sunatCode = trim($branch?->sunat_code ?? '');
                                    $isPrincipal = (int)$value === 1;
                                    if (!$isPrincipal && (empty($sunatCode) || $sunatCode === '0000')) {
                                        $fail("⚠️ La tienda '{$branch->name}' no tiene configurado un código de anexo SUNAT válido (distinto de 0000). No se pueden emitir Guías de Remisión.");
                                    }
                                }
                            ])
                            ->helperText(function (Forms\Get $get) {
                                $branchId = $get('branch_id') ?? auth()->user()?->branch_id ?? 1;
                                $branch = \App\Models\Branch::find($branchId);
                                $sunatCode = trim($branch?->sunat_code ?? '');
                                $isPrincipal = (int)$branchId === 1;
                                if (!$isPrincipal && (empty($sunatCode) || $sunatCode === '0000')) {
                                    return '⚠️ Esta sucursal no tiene registrado su código de anexo SUNAT (distinto de 0000). No puede emitir Guías de Remisión.';
                                }
                                return null;
                            }),

                        Forms\Components\Select::make('customer_id')
                            ->label('Destinatario / Cliente')
                            ->relationship('customer', 'name', modifyQueryUsing: fn ($query) => $query->limit(50))
                            ->getOptionLabelFromRecordUsing(fn (\App\Models\Customer $record) => $record->getOptionLabel())
                            ->getOptionLabelUsing(fn ($value) => ($c = \App\Models\Customer::find($value)) ? $c->getOptionLabel() : null)
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => \App\Models\Customer::searchByTerm($search, 50))
                            ->preload()
                            ->required(),

                        Forms\Components\TextInput::make('serie')
                            ->label('Serie (Ej: T001)')
                            ->default('T001')
                            ->required()
                            ->length(4)
                            ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                            ->dehydrateStateUsing(fn ($state) => strtoupper($state))
                            ->rules(fn (Forms\Get $get): array => [
                                function (string $attribute, $value, \Closure $fail) {
                                    if (!$value) return;
                                    if (!str_starts_with(strtoupper($value), 'T')) {
                                        $fail('⚠️ La serie de Guía debe empezar con la letra "T" (Ej: T001).');
                                    }
                                }
                            ]),

                        Forms\Components\DatePicker::make('issue_date')
                            ->label('Fecha de Emisión')
                            ->default(now())
                            ->required(),

                        Forms\Components\DatePicker::make('transfer_date')
                            ->label('Fecha de Inicio de Traslado')
                            ->default(now())
                            ->required(),
                    ])->columns(3),

                Forms\Components\Section::make('Datos del Envío')
                    ->schema([
                        // Origen
                        Forms\Components\Select::make('departure_ubigeo')
                            ->label('Ubigeo de Partida (Origen)')
                            ->getSearchResultsUsing(fn (string $search): array => 
                                DB::table('ubi_distritos')
                                    ->join('ubi_provincias', 'ubi_distritos.provincia_id', '=', 'ubi_provincias.id')
                                    ->join('ubi_regiones', 'ubi_distritos.region_id', '=', 'ubi_regiones.id')
                                    ->where('ubi_distritos.nombre', 'like', "%{$search}%")
                                    ->orWhere('ubi_provincias.nombre', 'like', "%{$search}%")
                                    ->select(DB::raw("ubi_distritos.id, CONCAT(ubi_regiones.nombre, ' - ', ubi_provincias.nombre, ' - ', ubi_distritos.nombre) as full_name"))
                                    ->limit(20)
                                    ->pluck('full_name', 'id')
                                    ->toArray()
                            )
                            ->getOptionLabelUsing(fn ($value): ?string => 
                                DB::table('ubi_distritos')
                                    ->join('ubi_provincias', 'ubi_distritos.provincia_id', '=', 'ubi_provincias.id')
                                    ->join('ubi_regiones', 'ubi_distritos.region_id', '=', 'ubi_regiones.id')
                                    ->where('ubi_distritos.id', $value)
                                    ->select(DB::raw("CONCAT(ubi_regiones.nombre, ' - ', ubi_provincias.nombre, ' - ', ubi_distritos.nombre) as full_name"))
                                    ->value('full_name')
                            )
                            ->searchable()
                            ->required(),

                        Forms\Components\TextInput::make('departure_address')
                            ->label('Dirección de Partida')
                            ->required(),

                        // Destino
                        Forms\Components\Select::make('arrival_ubigeo')
                            ->label('Ubigeo de Llegada (Destino)')
                            ->getSearchResultsUsing(fn (string $search): array => 
                                DB::table('ubi_distritos')
                                    ->join('ubi_provincias', 'ubi_distritos.provincia_id', '=', 'ubi_provincias.id')
                                    ->join('ubi_regiones', 'ubi_distritos.region_id', '=', 'ubi_regiones.id')
                                    ->where('ubi_distritos.nombre', 'like', "%{$search}%")
                                    ->orWhere('ubi_provincias.nombre', 'like', "%{$search}%")
                                    ->select(DB::raw("ubi_distritos.id, CONCAT(ubi_regiones.nombre, ' - ', ubi_provincias.nombre, ' - ', ubi_distritos.nombre) as full_name"))
                                    ->limit(20)
                                    ->pluck('full_name', 'id')
                                    ->toArray()
                            )
                            ->getOptionLabelUsing(fn ($value): ?string => 
                                DB::table('ubi_distritos')
                                    ->join('ubi_provincias', 'ubi_distritos.provincia_id', '=', 'ubi_provincias.id')
                                    ->join('ubi_regiones', 'ubi_distritos.region_id', '=', 'ubi_regiones.id')
                                    ->where('ubi_distritos.id', $value)
                                    ->select(DB::raw("CONCAT(ubi_regiones.nombre, ' - ', ubi_provincias.nombre, ' - ', ubi_distritos.nombre) as full_name"))
                                    ->value('full_name')
                            )
                            ->searchable()
                            ->required(),

                        Forms\Components\TextInput::make('arrival_address')
                            ->label('Dirección de Llegada')
                            ->required(),
                    ])->columns(2),

                Forms\Components\Section::make('Parámetros de Carga y Traslado')
                    ->schema([
                        Forms\Components\Select::make('transfer_reason_code')
                            ->label('Motivo del Traslado')
                            ->options([
                                '01' => 'Venta',
                                '02' => 'Compra',
                                '04' => 'Traslado entre establecimientos de la misma empresa',
                                '08' => 'Importación',
                                '09' => 'Exportación',
                                '13' => 'Otros',
                            ])
                            ->default('01')
                            ->required(),

                        Forms\Components\TextInput::make('transfer_reason_description')
                            ->label('Detalle del Motivo (Opcional)'),

                        Forms\Components\Select::make('mode_of_transport')
                            ->label('Modalidad de Transporte')
                            ->options([
                                '02' => 'Transporte Privado',
                                '01' => 'Transporte Público',
                            ])
                            ->default('02')
                            ->required()
                            ->live(),

                        Forms\Components\TextInput::make('total_weight')
                            ->label('Peso Bruto Total (KGM)')
                            ->numeric()
                            ->default(1.00)
                            ->required(),

                        Forms\Components\TextInput::make('number_of_packages')
                            ->label('Número de Bultos/Paquetes')
                            ->numeric()
                            ->default(1)
                            ->required(),

                        Forms\Components\TextInput::make('observations')
                            ->label('Observaciones (Opcional)'),
                    ])->columns(3),

                Forms\Components\Section::make('Detalles del Transporte')
                    ->schema([
                        // Privado
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\Select::make('driver_document_type')
                                    ->label('Tipo Doc. Conductor')
                                    ->options(['1' => 'DNI', '4' => 'Carnet de Extranjería', '6' => 'RUC'])
                                    ->default('1'),
                                Forms\Components\TextInput::make('driver_document_number')
                                    ->label('Nro Doc. Conductor'),
                                Forms\Components\TextInput::make('driver_name')
                                    ->label('Nombre del Conductor'),
                                Forms\Components\TextInput::make('driver_license_number')
                                    ->label('Nro Licencia de Conducir'),
                                Forms\Components\TextInput::make('vehicle_plate')
                                    ->label('Placa del Vehículo'),
                                Forms\Components\TextInput::make('secondary_vehicle_plate')
                                    ->label('Placa Semirremolque (Opcional)'),
                            ])
                            ->visible(fn (Forms\Get $get) => $get('mode_of_transport') === '02'),

                        // Público
                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\Select::make('carrier_document_type')
                                    ->label('Tipo Doc. Transportista')
                                    ->options(['6' => 'RUC'])
                                    ->default('6'),
                                Forms\Components\TextInput::make('carrier_document_number')
                                    ->label('RUC Transportista'),
                                Forms\Components\TextInput::make('carrier_name')
                                    ->label('Razón Social Transportista'),
                            ])
                            ->visible(fn (Forms\Get $get) => $get('mode_of_transport') === '01'),
                    ]),

                Forms\Components\Section::make('Items a Transportar')
                    ->schema([
                        Forms\Components\Repeater::make('items')
                            ->relationship('items')
                            ->schema([
                                Forms\Components\Select::make('product_variant_id')
                                    ->label('Buscar prenda')
                                    ->options(function (Forms\Get $get) {
                                        $branchId = $get('../../branch_id') ?? auth()->user()?->branch_id ?? 1;
                                        return ProductVariant::with('product')
                                            ->whereHas('product.branches', fn($b) => $b->where('branches.id', $branchId))
                                            ->limit(100)
                                            ->get()
                                            ->mapWithKeys(fn ($pv) => [$pv->id => "{$pv->sku} - {$pv->product?->name}"]);
                                    })
                                    ->searchable()
                                    ->live()
                                    ->afterStateUpdated(function ($state, Forms\Set $set) {
                                        if ($state) {
                                            $pv = ProductVariant::with('product')->find($state);
                                            if ($pv) {
                                                $set('product_name', $pv->product?->name);
                                            }
                                        }
                                    }),
                                Forms\Components\TextInput::make('product_name')
                                    ->label('Descripción de la prenda')
                                    ->required(),
                                Forms\Components\TextInput::make('quantity')
                                    ->label('Cantidad')
                                    ->numeric()
                                    ->default(1.00)
                                    ->required(),
                                Forms\Components\Select::make('unit_code')
                                    ->label('Unidad')
                                    ->options(['NIU' => 'Unidades', 'KGM' => 'Kilogramos', 'GLI' => 'Galones'])
                                    ->default('NIU')
                                    ->required(),
                            ])
                            ->columns(4)
                            ->required(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('serie')
                    ->label('Nro Guía')
                    ->formatStateUsing(fn ($state, Despatch $record) => $state . '-' . str_pad($record->correlative, 8, '0', STR_PAD_LEFT))
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('customer.name')
                    ->label('Destinatario')
                    ->searchable(query: function (\Illuminate\Database\Eloquent\Builder $query, string $search): \Illuminate\Database\Eloquent\Builder {
                        $search = trim($search);
                        return $query->whereHas('customer', function (\Illuminate\Database\Eloquent\Builder $q) use ($search) {
                            $q->where(function (\Illuminate\Database\Eloquent\Builder $sub) use ($search) {
                                $sub->where('name', 'like', "%{$search}%")
                                    ->orWhere('document_number', 'like', "{$search}%");
                            });
                        });
                    }),

                Tables\Columns\TextColumn::make('issue_date')
                    ->label('Fecha Emisión')
                    ->date('d/m/Y'),

                Tables\Columns\TextColumn::make('transfer_date')
                    ->label('Fecha Traslado')
                    ->date('d/m/Y'),

                Tables\Columns\TextColumn::make('mode_of_transport')
                    ->label('Modalidad')
                    ->formatStateUsing(fn ($state) => $state === '01' ? 'Público' : 'Privado'),

                Tables\Columns\TextColumn::make('sunat_status')
                    ->label('Estado SUNAT')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pendiente' => 'warning',
                        'aceptado' => 'success',
                        'anulado' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Sucursal')
                    ->badge()
                    ->color('info')
                    ->sortable()
                    ->visible(fn () => auth()->user()?->seesAllBranches() ?? false),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('branch_id')
                    ->relationship('branch', 'name')
                    ->label('Sucursal')
                    ->searchable()
                    ->preload()
                    ->visible(fn () => auth()->user()?->seesAllBranches() ?? false),

                Tables\Filters\SelectFilter::make('sunat_status')
                    ->label('Estado SUNAT')
                    ->options([
                        'pendiente' => 'Pendiente',
                        'aceptado' => 'Aceptado',
                        'anulado' => 'Anulado',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()->label('Ver'),
                
                // Imprimir Guía A4
                Tables\Actions\Action::make('imprimir_guia')
                    ->label('PDF')
                    ->icon('heroicon-o-document-text')
                    ->color('info')
                    ->url(fn (Despatch $record): string => route('despatches.pdf', $record->id))
                    ->openUrlInNewTab(),

                // Declarar a SUNAT
                Tables\Actions\Action::make('declarar_sunat')
                    ->label('Declarar')
                    ->icon('heroicon-o-cloud-arrow-up')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (Despatch $record) => $record->sunat_status !== 'aceptado')
                    ->action(function (Despatch $record) {
                        $branch = $record->branch;
                        $sunatCode = trim($branch?->sunat_code ?? '');
                        $isPrincipal = (int)$record->branch_id === 1;
                        if (!$isPrincipal && (empty($sunatCode) || $sunatCode === '0000')) {
                            Notification::make()
                                ->title('Emisión Bloqueada')
                                ->body("La tienda '{$branch->name}' no tiene configurado un código de anexo SUNAT válido (distinto de 0000). Registre el código antes de emitir la guía.")
                                ->danger()
                                ->send();
                            return;
                        }

                        $service = new \App\Services\SunatService();
                        $res = $service->enviarGuia($record);

                        if (isset($res['success'])) {
                            $record->update(['sunat_status' => 'aceptado']);
                            $msg = isset($res['is_local_simulated']) ? '¡Guía XML generada y firmada localmente!' : '¡Guía Aceptada por SUNAT!';
                            Notification::make()->title($msg)->success()->send();
                        } else {
                            Notification::make()->title('Error SUNAT')->body($res['error'] ?? 'Ocurrió un error inesperado')->danger()->send();
                        }
                    }),
            ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                InfoSection::make('Información del Documento')
                    ->schema([
                        TextEntry::make('serie')
                            ->label('Número de Guía')
                            ->formatStateUsing(fn ($state, Despatch $record) => $state . '-' . str_pad($record->correlative, 8, '0', STR_PAD_LEFT)),
                        TextEntry::make('customer.name')->label('Destinatario'),
                        TextEntry::make('customer.document_number')->label('RUC/DNI Destinatario'),
                        TextEntry::make('issue_date')->label('Fecha Emisión')->date('d/m/Y'),
                        TextEntry::make('transfer_date')->label('Fecha Traslado')->date('d/m/Y'),
                        TextEntry::make('sunat_status')
                            ->label('Estado SUNAT')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                'pendiente' => 'warning',
                                'aceptado' => 'success',
                                'anulado' => 'danger',
                                default => 'gray',
                            }),
                    ])->columns(3),

                InfoSection::make('Datos del Envío y Carga')
                    ->schema([
                        TextEntry::make('departure_address')->label('Dirección de Partida'),
                        TextEntry::make('arrival_address')->label('Dirección de Llegada'),
                        TextEntry::make('total_weight')->label('Peso Total (KGM)'),
                        TextEntry::make('number_of_packages')->label('Cant. Bultos'),
                        TextEntry::make('observations')->label('Observaciones')->default('-'),
                    ])->columns(2),

                InfoSection::make('Detalles del Transporte')
                    ->schema([
                        TextEntry::make('driver_name')
                            ->label('Conductor')
                            ->visible(fn ($record) => $record->mode_of_transport === '02'),
                        TextEntry::make('driver_license_number')
                            ->label('Licencia de Conducir')
                            ->visible(fn ($record) => $record->mode_of_transport === '02'),
                        TextEntry::make('vehicle_plate')
                            ->label('Placa del Vehículo')
                            ->visible(fn ($record) => $record->mode_of_transport === '02'),

                        TextEntry::make('carrier_name')
                            ->label('Transportista (Empresa)')
                            ->visible(fn ($record) => $record->mode_of_transport === '01'),
                        TextEntry::make('carrier_document_number')
                            ->label('RUC Transportista')
                            ->visible(fn ($record) => $record->mode_of_transport === '01'),
                    ])->columns(3),

                InfoSection::make('Items Trasladados')
                    ->schema([
                        RepeatableEntry::make('items')
                            ->label('')
                            ->schema([
                                TextEntry::make('product_name')->label('Prenda'),
                                TextEntry::make('quantity')->label('Cantidad'),
                                TextEntry::make('unit_code')->label('Unidad'),
                            ])->columns(3)
                    ]),
            ]);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getEloquentQuery()->with(['branch', 'customer', 'sale']);

        if (!auth()->user()?->seesAllBranches()) {
            $userBranchId = auth()->user()?->branch_id ?? 1;
            $query->where('branch_id', $userBranchId);
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDespatches::route('/'),
            'create' => Pages\CreateDespatch::route('/create'),
            'view' => Pages\ViewDespatch::route('/{record}'),
        ];
    }
}
