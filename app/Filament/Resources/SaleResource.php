<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SaleResource\Pages;
use App\Models\Sale;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Filament\Notifications\Notification;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Illuminate\Support\Facades\Auth;
use Illuminate\Database\Eloquent\Builder;
use Filament\Navigation\NavigationItem;
use App\Services\SunatNoteReader;

class SaleResource extends Resource
{
    protected static ?string $model = Sale::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';
    protected static ?string $navigationGroup = '1. Ventas y Cajas'; 
    protected static ?int $navigationSort = 1; 
    protected static ?string $modelLabel = 'Venta';

    public static function getNavigationItems(): array
    {
        return [
            NavigationItem::make(static::getNavigationLabel())
                ->group(static::getNavigationGroup())
                ->icon(static::getNavigationIcon())
                ->activeIcon(static::getActiveNavigationIcon())
                ->isActiveWhen(fn () => request()->routeIs(static::getRouteBaseName() . '.*') && !request()->has('tableFilters.con_notas') && !request()->has('con_notas'))
                ->sort(static::getNavigationSort())
                ->badge(static::getNavigationBadge(), color: static::getNavigationBadgeColor())
                ->url(static::getUrl()),

            NavigationItem::make('Notas de Crédito y Débito')
                ->group(static::getNavigationGroup())
                ->icon('heroicon-o-document-duplicate')
                ->isActiveWhen(fn () => request()->routeIs(static::getRouteBaseName() . '.*') && (request()->input('tableFilters.con_notas.isActive') == 'true' || request()->input('tableFilters.con_notas.isActive') == '1' || request()->boolean('con_notas')))
                ->sort((static::getNavigationSort() ?? 1) + 1)
                ->url(static::getUrl('index', ['tableFilters[con_notas][isActive]' => 'true'])),
        ];
    }

    public static function form(Form $form): Form
    {
        $getSaleTotal = function (Get $get) {
            if ($get('is_quick_sale')) {
                return (float)($get('quick_sale_total') ?? 0);
            }
            return collect($get('items') ?? [])
                ->map(fn($item) => (float)($item['total'] ?? 0))
                ->sum();
        };

        $getPaymentsTotal = function (Get $get) {
            return collect($get('payments') ?? [])
                ->map(fn($p) => (float)($p['amount'] ?? 0))
                ->sum();
        };

        $getCashAmount = function (Get $get) {
            return collect($get('payments') ?? [])
                ->where('payment_method', 'efectivo')
                ->map(fn($p) => (float)($p['amount'] ?? 0))
                ->sum();
        };

        return $form
            ->schema([
                // ==========================================
                // 1. CABECERA: SUCURSAL, CLIENTE Y DOCUMENTO
                // ==========================================
                Forms\Components\Section::make('Cabecera de Venta')
                    ->schema([
                        Forms\Components\Grid::make(12)
                            ->schema([
                                Forms\Components\Toggle::make('is_quick_sale')
                                    ->label('⚡ Venta rápida (función retirada; solo consulta de ventas antiguas)')
                                    ->visible(fn (?Sale $record) => (bool) $record?->is_quick_sale)
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->afterStateUpdated(function (Set $set) {
                                        $set('items', []);
                                    })
                                    ->columnSpan(12),

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
                                    ->columnSpan(['default' => 12, 'md' => 6])
                                    ->createOptionForm([
                                        Forms\Components\Select::make('document_type')
                                            ->label('Tipo')
                                            ->options(['1' => 'DNI', '6' => 'RUC'])
                                            ->required()
                                            ->live()
                                            ->afterStateUpdated(fn(Forms\Set $set) => $set('document_number', null)),
                                        Forms\Components\TextInput::make('document_number')
                                            ->label('Número de Documento')
                                            ->required()
                                            ->unique(table: 'customers', column: 'document_number', ignoreRecord: true)
                                            ->suffixAction(
                                                Forms\Components\Actions\Action::make('search')
                                                    ->icon('heroicon-o-magnifying-glass')
                                                    ->action(function (Forms\Set $set, Forms\Get $get) {
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
                                                                \Filament\Notifications\Notification::make()->title('Cliente Encontrado')->success()->send();
                                                            }
                                                        } catch (\Exception $e) {
                                                            \Filament\Notifications\Notification::make()->title('Error en API')->danger()->send();
                                                        }
                                                    })
                                            ),
                                        Forms\Components\TextInput::make('name')->label('Nombre / Razón Social')->required(),
                                        Forms\Components\TextInput::make('address')
                                            ->label('Dirección Fiscal')
                                            ->visible(fn(Forms\Get $get) => $get('document_type') === '6'),
                                    ]),

                                Forms\Components\Select::make('branch_id')
                                    ->label('Sucursal de Venta')
                                    ->relationship(
                                        'branch',
                                        'name',
                                        modifyQueryUsing: function ($query, string $operation) {
                                            if ($operation === 'create') {
                                                $user = Auth::user();
                                                if ($user && $user->hasRole('super_admin')) {
                                                    $hoyLima = now()->setTimezone('America/Lima')->startOfDay();
                                                    return $query->whereHas('cashRegisters', function ($q) use ($hoyLima) {
                                                        $q->where('status', 'abierta')
                                                          ->where('opened_at', '>=', $hoyLima);
                                                    });
                                                }
                                            }
                                            return $query;
                                        }
                                    )
                                    ->required()
                                    ->live()
                                    ->prefixIcon('heroicon-m-building-storefront')
                                    ->default(function () {
                                        $user = Auth::user();
                                        if ($user && $user->hasRole('super_admin')) {
                                            $hoyLima = now()->setTimezone('America/Lima')->startOfDay();
                                            if ($user->branch_id && \App\Models\CashRegister::where('branch_id', $user->branch_id)->where('status', 'abierta')->where('opened_at', '>=', $hoyLima)->exists()) {
                                                return $user->branch_id;
                                            }
                                            return \App\Models\CashRegister::where('status', 'abierta')->where('opened_at', '>=', $hoyLima)->value('branch_id');
                                        }
                                        return Auth::user()?->branch_id;
                                    })
                                    ->disabled(fn () => !Auth::user()?->hasRole('super_admin'))
                                    ->dehydrated()
                                    ->afterStateUpdated(function (Set $set, $state) {
                                        $set('serie', null);
                                        $set('preview_correlative', null);
                                        $set('items', []);
                                        $defaultWh = \App\Models\Warehouse::where('branch_id', $state)->where('is_default', true)->value('id')
                                            ?? \App\Models\Warehouse::where('branch_id', $state)->where('is_active', true)->value('id');
                                        $set('warehouse_id', $defaultWh);
                                    })
                                    ->columnSpan(['default' => 12, 'md' => 3]),

                                Forms\Components\Select::make('warehouse_id')
                                    ->label('Almacén')
                                    ->options(function (Get $get) {
                                        $branchId = $get('branch_id') ?? Auth::user()?->branch_id ?? 1;
                                        return \App\Models\Warehouse::where('branch_id', $branchId)
                                            ->where('is_active', true)
                                            ->pluck('name', 'id');
                                    })
                                    ->default(function (Get $get) {
                                        $branchId = $get('branch_id') ?? Auth::user()?->branch_id ?? 1;
                                        return \App\Models\Warehouse::where('branch_id', $branchId)->where('is_default', true)->value('id')
                                            ?? \App\Models\Warehouse::where('branch_id', $branchId)->where('is_active', true)->value('id');
                                    })
                                    ->required()
                                    ->live()
                                    ->prefixIcon('heroicon-m-home-modern')
                                    ->helperText('Almacén que descuenta stock')
                                    ->disabled(fn () => !Auth::user()?->hasRole('super_admin'))
                                    ->dehydrated()
                                    ->afterStateUpdated(function (Set $set) {
                                        $set('items', []);
                                    })
                                    ->columnSpan(['default' => 12, 'md' => 3]),

                                Forms\Components\Select::make('serie')
                                    ->label('Tipo de Comprobante')
                                    ->options(function (Get $get) {
                                        $branchId = $get('branch_id');
                                        if (!$branchId) return [];

                                        $branch = \App\Models\Branch::find($branchId);
                                        $sunatCode = trim($branch?->sunat_code ?? '');
                                        $isPrincipal = (int)$branchId === 1;

                                        $query = \App\Models\Serie::where('is_active', true)
                                            ->where('branch_id', $branchId)
                                            ->whereIn('document_type', ['00', '01', '03']);

                                        // Si una tienda distinta de la principal tiene el código SUNAT vacío o en 0000:
                                        // solo permitir Notas de Venta internas (00)
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
                                    ->helperText(function (Get $get) {
                                        $branchId = $get('branch_id');
                                        if (!$branchId) return null;
                                        $branch = \App\Models\Branch::find($branchId);
                                        $sunatCode = trim($branch?->sunat_code ?? '');
                                        $isPrincipal = (int)$branchId === 1;
                                        if (!$isPrincipal && (empty($sunatCode) || $sunatCode === '0000')) {
                                            return '⚠️ Esta sucursal no tiene registrado su código de anexo SUNAT (distinto de 0000). Solo puede emitir Notas de Venta internas.';
                                        }
                                        return null;
                                    })
                                    ->required()
                                    ->searchable()
                                    ->preload()
                                    ->live()
                                    ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                                        $branchId = $get('branch_id');
                                        $serie = \App\Models\Serie::where('serie', $state)
                                            ->where('branch_id', $branchId)
                                            ->whereIn('document_type', ['00', '01', '03'])
                                            ->first();
                                        if ($serie) {
                                            $set('preview_correlative', $serie->serie . '-' . $serie->current_number);
                                        } else {
                                            $set('preview_correlative', null);
                                        }
                                    })
                                    ->rules(fn (Forms\Get $get): array => [
                                        function (string $attribute, $value, \Closure $fail) use ($get) {
                                            $branchId = $get('branch_id');
                                            if ($branchId && !str_starts_with($value, 'NV')) {
                                                $branch = \App\Models\Branch::find($branchId);
                                                $sunatCode = trim($branch?->sunat_code ?? '');
                                                $isPrincipal = (int)$branchId === 1;
                                                if (!$isPrincipal && (empty($sunatCode) || $sunatCode === '0000')) {
                                                    $fail("⚠️ La tienda '{$branch->name}' no tiene configurado un código de anexo SUNAT válido (distinto de 0000). Solo se permiten Notas de Venta.");
                                                }
                                            }

                                            $clienteId = $get('customer_id');
                                            if (!$clienteId || !$value) return;
                                            $cliente = \App\Models\Customer::find($clienteId);
                                            if (str_starts_with($value, 'F') && $cliente?->document_type === '1') {
                                                $fail('⚠️ No puedes emitir Factura a un cliente con DNI. Selecciona Boleta.');
                                            }
                                        }
                                    ])
                                    ->columnSpan(['default' => 12, 'md' => 4]),

                                Forms\Components\DateTimePicker::make('issue_date')
                                    ->label('Fecha y Hora')
                                    ->default(now())
                                    ->required()
                                    ->native(false) 
                                    ->displayFormat('d/m/Y H:i') 
                                    ->format('Y-m-d H:i:s') 
                                    ->seconds(false) 
                                    ->columnSpan(['default' => 12, 'md' => 4]),

                                Forms\Components\Select::make('seller_id')
                                    ->label('Vendedor')
                                    ->relationship('seller', 'name', function (Builder $query, Get $get) {
                                        $branchId = $get('branch_id') ?? Auth::user()?->branch_id ?? 1;
                                        return $query->where(function ($q) use ($branchId) {
                                            $q->where('branch_id', $branchId)
                                              ->orWhereHas('roles', fn($r) => $r->where('name', 'super_admin'));
                                        });
                                    })
                                    ->searchable()
                                    ->preload()
                                    ->default(fn () => Auth::id())
                                    ->required()
                                    ->prefixIcon('heroicon-m-user-circle')
                                    ->columnSpan(['default' => 12, 'md' => 4]),

                                Forms\Components\Hidden::make('preview_correlative')
                                    ->dehydrated(false),
                            ]),
                    ]),

                // ==========================================
                // 2. DETALLE: PRODUCTOS Y SERVICIOS
                // ==========================================
                Forms\Components\Section::make('Detalle de Prendas')
                    ->visible(fn(Get $get) => !$get('is_quick_sale'))
                    // 🌟 EL BOTÓN SE MUEVE AL ENCABEZADO DE LA SECCIÓN
                    ->headerActions([
                        Forms\Components\Actions\Action::make('add_service')
                            ->label('Añadir Servicio')
                            ->icon('heroicon-o-wrench-screwdriver')
                            ->color('warning')
                            ->form([
                                Forms\Components\Select::make('product_variant_id')
                                    ->label('Seleccionar Servicio')
                                    ->options(function () {
                                        return \App\Models\ProductVariant::whereHas('product', function ($query) {
                                            $query->where('is_service', true);
                                        })->get()->mapWithKeys(function ($variant) {
                                            return [$variant->id => "🔧 " . ($variant->product?->name ?? 'Servicio')];
                                        });
                                    })
                                    ->searchable()
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(function ($state, Forms\Set $set) {
                                        $variante = \App\Models\ProductVariant::find($state);
                                        if ($variante && $variante->product) {
                                            $set('price', $variante->product->sale_price ?? 0);
                                        }
                                    }),
                                    
                                Forms\Components\TextInput::make('price')
                                    ->label('Precio Acordado')
                                    ->numeric()
                                    ->prefix('S/')
                                    ->required(),
                                    
                                Forms\Components\TextInput::make('quantity')
                                    ->label('Cantidad')
                                    ->numeric()
                                    ->default(1)
                                    ->required(),
                            ])
                            ->action(function (array $data, Forms\Set $set, Forms\Get $get) {
                                $items = $get('items') ?? [];
                                $uuid = (string) \Illuminate\Support\Str::uuid();
                                
                                $variante = \App\Models\ProductVariant::find($data['product_variant_id']);
                                $precio = (float) $data['price'];
                                $cant = (int) $data['quantity'];
                                $totalLinea = $precio * $cant;
                                
                                $items[$uuid] = [
                                    'product_variant_id' => $data['product_variant_id'],
                                    'quantity' => $cant,
                                    'price' => $precio,
                                    'total' => number_format($totalLinea, 2, '.', ''),
                                    'igv' => round($totalLinea - ($totalLinea / 1.18), 2),
                                    'product_name' => ($variante && $variante->product) ? $variante->product->name : 'Servicio',
                                    'max_stock' => 999999, 
                                ];
                                
                                $set('items', $items);
                            }),
                    ])
                    ->schema([
                        Forms\Components\Repeater::make('items')
                            ->hiddenLabel() 
                            ->relationship('items')
                            ->addActionLabel('➕ Agregar prenda')
                            ->hidden(fn(Get $get) => (bool)$get('is_quick_sale'))
                            ->defaultItems(1) 
                            ->schema([
                                Forms\Components\Grid::make(12) 
                                    ->schema([
                                        Forms\Components\Select::make('product_variant_id')
                                            ->label('Prenda (modelo / color / talla)')
                                            ->searchable()
                                            ->getSearchResultsUsing(function (string $search, Get $get) {
                                                $branchId = $get('../../branch_id');
                                                $warehouseId = $get('../../warehouse_id');
                                                if (!$branchId) return [];

                                                if (!$warehouseId) {
                                                    $warehouseId = \App\Models\Warehouse::where('branch_id', $branchId)->where('is_default', true)->value('id')
                                                        ?? \App\Models\Warehouse::where('branch_id', $branchId)->value('id');
                                                }

                                                $opciones = [];

                                                $query = \App\Models\Inventory::with(['productVariant.product'])
                                                    ->whereHas('productVariant.product.branches', function ($b) use ($branchId) {
                                                        $b->where('branches.id', $branchId);
                                                    });

                                                if ($warehouseId) {
                                                    $query->where('warehouse_id', $warehouseId);
                                                } else {
                                                    $query->where('branch_id', $branchId);
                                                }

                                                if (!empty($search)) {
                                                    $query->whereHas('productVariant', function ($q) use ($search) {
                                                        $q->where('sku', 'like', "%{$search}%")
                                                          ->orWhereHas('product', function ($qp) use ($search) {
                                                              $qp->where('name', 'like', "%{$search}%");
                                                          });
                                                    })
                                                    ->orderByRaw("CASE 
                                                        WHEN EXISTS (
                                                            SELECT 1 FROM product_variants 
                                                            WHERE product_variants.id = inventories.product_variant_id 
                                                            AND product_variants.sku = ?
                                                        ) THEN 1 
                                                        WHEN EXISTS (
                                                            SELECT 1 FROM product_variants 
                                                            WHERE product_variants.id = inventories.product_variant_id 
                                                            AND product_variants.sku LIKE ?
                                                        ) THEN 2 
                                                        ELSE 3 
                                                    END", [$search, $search . '%']);
                                                }

                                                $inventarios = $query->limit(50)->get();

                                                foreach ($inventarios as $inv) {
                                                    $variant = $inv->productVariant;
                                                    if (!$variant || !$variant->product) continue;
                                                    $prefix = $inv->stock <= 0 ? '⚠️ [SIN STOCK] ' : '';
                                                    $opciones[$variant->id] = "{$prefix}[{$variant->sku}] {$variant->product->name} - [{$variant->color_name} / {$variant->size}] (Stock: {$inv->stock})";
                                                }

                                                $serviciosQuery = \App\Models\ProductVariant::with('product')
                                                    ->whereHas('product', function($q) {
                                                        $q->where('is_service', true);
                                                    });

                                                if (!empty($search)) {
                                                    $serviciosQuery->whereHas('product', function($q) use ($search) {
                                                        $q->where('name', 'like', "%{$search}%");
                                                    });
                                                }

                                                $servicios = $serviciosQuery->limit(10)->get();
                                                
                                                foreach($servicios as $ser) {
                                                    if ($ser->product) {
                                                        $opciones[$ser->id] = "🔧 {$ser->product->name} (Servicio)";
                                                    }
                                                }

                                                return $opciones;
                                            })
                                            ->getOptionLabelUsing(function ($value) {
                                                $variant = \App\Models\ProductVariant::with(['product'])->find($value);
                                                if (!$variant) return '';
                                                if ($variant->product?->is_service) {
                                                    return "🔧 {$variant->product->name} (Servicio)";
                                                }
                                                $inv = \App\Models\Inventory::where('product_variant_id', $value)->first();
                                                $stock = $inv ? $inv->stock : 0;
                                                $prefix = $stock <= 0 ? '⚠️ [SIN STOCK] ' : '';
                                                return "{$prefix}[{$variant->sku}] {$variant->product?->name} - [{$variant->color_name} / {$variant->size}] (Stock: {$stock})";
                                            })
                                            ->required()
                                            ->live()
                                            ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                                                $variante = \App\Models\ProductVariant::find($state);
                                                if ($variante && $variante->product) {
                                                    if ($variante->sku === 'REPE-GENERICO') {
                                                        $set('is_generic', true);
                                                        $set('product_name', '');
                                                        $set('price', 0);
                                                        $set('max_stock', 999999);
                                                        $set('total', 0);
                                                        $set('igv', 0);
                                                    } else {
                                                        $set('is_generic', false);
                                                        $set('price', $variante->product->sale_price ?? 0);
                                                        
                                                        if ($variante->product->is_service) {
                                                            $set('product_name', "🔧 " . $variante->product->name);
                                                            $set('max_stock', 999999);
                                                        } else {
                                                            $set('product_name', "{$variante->product->name} ({$variante->color_name} - {$variante->size})");
                                                            $branchId = $get('../../branch_id');
                                                            $warehouseId = $get('../../warehouse_id');
                                                            if (!$warehouseId && $branchId) {
                                                                $warehouseId = \App\Models\Warehouse::where('branch_id', $branchId)->where('is_default', true)->value('id')
                                                                    ?? \App\Models\Warehouse::where('branch_id', $branchId)->value('id');
                                                            }
                                                            $stockQuery = \App\Models\Inventory::where('product_variant_id', $state);
                                                            if ($warehouseId) {
                                                                $stockQuery->where('warehouse_id', $warehouseId);
                                                            } else {
                                                                $stockQuery->where('branch_id', $branchId);
                                                            }
                                                            $stock = $stockQuery->value('stock') ?? 0;
                                                            $set('max_stock', $stock);

                                                            if ($stock <= 0) {
                                                                \Filament\Notifications\Notification::make()
                                                                    ->title('⚠️ Prenda sin stock')
                                                                    ->body('Este repuesto no cuenta con stock disponible en el almacén seleccionado. La venta procederá con stock negativo.')
                                                                    ->warning()
                                                                    ->send();
                                                            }
                                                        }

                                                        $cant = (int) $get('quantity') ?: 1;
                                                        $total = (float)($variante->product->sale_price ?? 0) * $cant;
                                                        $set('total', $total);
                                                        $set('igv', round($total - ($total / 1.18), 2));
                                                    }
                                                }
                                            })
                                            ->columnSpan(6),

                                        Forms\Components\TextInput::make('quantity')
                                            ->label('Cant.')
                                            ->numeric()
                                            ->default(1)
                                            ->required()
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                                                $variantId = $get('product_variant_id');
                                                if (!$variantId) return;

                                                // Solo verificar stock para productos reales (no genéricos)
                                                $variante = \App\Models\ProductVariant::find($variantId);
                                                if ($variante && $variante->sku !== 'REPE-GENERICO') {
                                                    $branchId = $get('../../branch_id');
                                                    $maxStock = (int) \App\Models\Inventory::where('product_variant_id', $variantId)
                                                        ->where('branch_id', $branchId)
                                                        ->sum('stock');

                                                    $itemsEnCarrito = $get('../../items') ?? [];
                                                    $cantidadTotalEnCarrito = 0;
                                                    foreach ($itemsEnCarrito as $item) {
                                                        if (($item['product_variant_id'] ?? null) == $variantId) {
                                                            $cantidadTotalEnCarrito += (int) ($item['quantity'] ?? 0);
                                                        }
                                                    }

                                                    if ($cantidadTotalEnCarrito > $maxStock) {
                                                        \Filament\Notifications\Notification::make()
                                                            ->title('⚠️ Stock Insuficiente')
                                                            ->body("La cantidad solicitada ({$cantidadTotalEnCarrito}) supera el stock disponible en esta tienda ({$maxStock}). La venta procederá con stock negativo.")
                                                            ->warning()
                                                            ->send();
                                                    }
                                                }

                                                $precio = (float) ($get('price') ?? 0);
                                                $totalLinea = $state * $precio;
                                                $set('total', number_format($totalLinea, 2, '.', ''));
                                                $set('igv', round($totalLinea - ($totalLinea / 1.18), 2));
                                            })
                                            ->columnSpan(2),

                                        Forms\Components\TextInput::make('price')
                                            ->label('Precio (S/)') 
                                            ->numeric()
                                            ->required()
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(function ($state, Forms\Set $set, Forms\Get $get) {
                                                $precio = (float) $state ?: 0;
                                                $cantidad = (int) ($get('quantity') ?: 1);
                                                $totalLinea = $precio * $cantidad;

                                                $set('total', number_format($totalLinea, 2, '.', ''));
                                                $set('igv', round($totalLinea - ($totalLinea / 1.18), 2));
                                            })
                                            ->columnSpan(2),

                                        Forms\Components\TextInput::make('total')
                                            ->label('Total (S/)') 
                                            ->readOnly()
                                            ->extraInputAttributes(['class' => 'font-bold text-right bg-gray-50'])
                                            ->columnSpan(2),

                                        Forms\Components\TextInput::make('product_name')
                                            ->label('📝 Descripción (se imprimirá en el comprobante)')
                                            ->required()
                                            ->placeholder('Se completa al elegir la prenda')
                                            ->columnSpan(12),

                                        Forms\Components\Hidden::make('is_generic')
                                            ->default(false)
                                            ->dehydrated(false),
                                        Forms\Components\Hidden::make('igv'),
                                        Forms\Components\Hidden::make('max_stock')->dehydrated(false),
                                    ]),
                            ]),
                    ]),

                Forms\Components\Section::make('Detalle de Venta Rápida')
                    ->visible(fn(Get $get) => (bool)$get('is_quick_sale'))
                    ->schema([
                        Forms\Components\TextInput::make('quick_sale_description')
                            ->label('Descripción de la venta rápida')
                            ->required(fn(Get $get) => (bool)$get('is_quick_sale'))
                            ->placeholder('Detalle general de los productos vendidos...')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('quick_sale_total')
                            ->label('Total Cobrado (S/)')
                            ->required(fn(Get $get) => (bool)$get('is_quick_sale'))
                            ->numeric()
                            ->live(debounce: 500)
                            ->prefix('S/'),
                    ]),

                // ==========================================
                // 3 Y 4. TOTALES, COBRO Y VUELTO 
                // ==========================================
                Forms\Components\Grid::make(12)
                    ->schema([
                        Forms\Components\Section::make('Cobro y Vuelto')
                            ->schema([
                                Forms\Components\Repeater::make('payments')
                                    ->label('Desglose de Pagos')
                                    ->relationship('payments')
                                    ->schema([
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
                                            ->required()
                                            ->live(),
                                        Forms\Components\TextInput::make('amount')
                                            ->label('Monto (S/)')
                                            ->numeric()
                                            ->required()
                                            ->live(debounce: 500),
                                    ])
                                    ->default([
                                        ['payment_method' => 'efectivo', 'amount' => 0]
                                    ])
                                    ->live()
                                    ->createItemButtonLabel('➕ Añadir Otro Medio de Pago')
                                    ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                        $recibido = (float)$get('amount_paid');
                                        $efectivoPagar = collect($state ?? [])
                                            ->where('payment_method', 'efectivo')
                                            ->map(fn($p) => (float)($p['amount'] ?? 0))
                                            ->sum();
                                        if ($recibido >= $efectivoPagar && $efectivoPagar > 0) {
                                            $set('balance_returned', number_format($recibido - $efectivoPagar, 2, '.', ''));
                                        } else {
                                            $set('balance_returned', '0.00');
                                        }
                                    })
                                    ->columnSpan(12),

                                Forms\Components\Grid::make(3)->schema([
                                    Forms\Components\Placeholder::make('payments_status_view')
                                        ->label('Estado de Pagos')
                                        ->content(fn(Get $get) => 'Total Venta: S/ ' . number_format($getSaleTotal($get), 2) . ' | Total Desglosado: S/ ' . number_format($getPaymentsTotal($get), 2) . ' | Restante: S/ ' . number_format(max(0, $getSaleTotal($get) - $getPaymentsTotal($get)), 2))
                                        ->columnSpan(3),

                                    Forms\Components\TextInput::make('amount_paid')
                                        ->label('Efectivo Recibido (S/)')
                                        ->numeric()
                                        ->live(debounce: 500)
                                        ->visible(fn(Get $get) => collect($get('payments') ?? [])->contains('payment_method', 'efectivo'))
                                        ->afterStateUpdated(function ($state, Set $set, Get $get) use ($getCashAmount) {
                                            $efectivoPagar = $getCashAmount($get);
                                            $recibido = (float) $state;

                                            if ($recibido >= $efectivoPagar && $efectivoPagar > 0) {
                                                $set('balance_returned', number_format($recibido - $efectivoPagar, 2, '.', ''));
                                            } else {
                                                $set('balance_returned', '0.00');
                                            }
                                        }),

                                    Forms\Components\TextInput::make('balance_returned')
                                        ->label('Vuelto (S/)')
                                        ->numeric()
                                        ->readOnly()
                                        ->visible(fn(Get $get) => collect($get('payments') ?? [])->contains('payment_method', 'efectivo'))
                                        ->extraInputAttributes(['class' => 'bg-green-100 font-bold text-xl text-green-700 text-center']),
                                ]),
                            ])
                            ->columnSpan(['default' => 12, 'lg' => 8]),

                        Forms\Components\Section::make('Resumen de Pago')
                            ->schema([
                                Forms\Components\Placeholder::make('subtotal_view')
                                    ->label('Subtotal')
                                    ->content(fn(Forms\Get $get) => 'S/ ' . number_format(($get('is_quick_sale') ? (float)$get('quick_sale_total') : collect($get('items'))->sum('total')) / 1.18, 2)),

                                Forms\Components\Placeholder::make('igv_view')
                                    ->label('IGV (18%)')
                                    ->content(fn(Forms\Get $get) => 'S/ ' . number_format(($get('is_quick_sale') ? (float)$get('quick_sale_total') : collect($get('items'))->sum('total')) - (($get('is_quick_sale') ? (float)$get('quick_sale_total') : collect($get('items'))->sum('total')) / 1.18), 2)),

                                Forms\Components\Placeholder::make('total_view')
                                    ->label('TOTAL A PAGAR')
                                    ->content(fn(Forms\Get $get) => 'S/ ' . number_format($get('is_quick_sale') ? (float)$get('quick_sale_total') : collect($get('items'))->sum('total'), 2))
                                    ->extraAttributes(['class' => 'text-3xl font-black text-primary-600 bg-primary-50 p-2 rounded-lg text-center mt-2']),

                                Forms\Components\Hidden::make('total')
                                    ->dehydrated()
                                    ->default(0),
                            ])
                            ->columnSpan(['default' => 12, 'lg' => 4]),
                    ]),

                Forms\Components\Section::make('Historial de Reemisiones SUNAT')
                    ->description('Comprobantes emitidos previamente para esta venta y rechazados por SUNAT')
                    ->collapsible()
                    ->visible(fn (?Sale $record) => !empty($record?->reemission_history))
                    ->schema([
                        Forms\Components\Repeater::make('reemission_history')
                            ->label('')
                            ->schema([
                                Forms\Components\TextInput::make('serie')->label('Serie')->disabled(),
                                Forms\Components\TextInput::make('correlative')->label('Correlativo')->disabled(),
                                Forms\Components\TextInput::make('sunat_error_code')->label('Cód. Error SUNAT')->disabled(),
                                Forms\Components\TextInput::make('sunat_error')->label('Motivo Rechazo SUNAT')->disabled(),
                                Forms\Components\TextInput::make('reemitted_at')->label('Fecha Reemisión')->disabled(),
                                Forms\Components\TextInput::make('reemitted_by_name')->label('Reemitido Por')->disabled(),
                            ])
                            ->columns(6)
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
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
                    ->limit(25)
                    ->description(fn(Sale $record): string => 'Doc: ' . ($record->customer?->document_number ?? '---')),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Cajero')
                    ->searchable()
                    ->badge()
                    ->color('gray')
                    ->icon('heroicon-m-user'),

                Tables\Columns\TextColumn::make('serie')
                    ->label('Comprobante')
                    ->searchable()
                    ->sortable()
                    ->formatStateUsing(fn(string $state, Sale $record) => $state . '-' . $record->correlative)
                    ->badge()
                    ->color(fn(string $state): string => str_starts_with($state, 'F') ? 'info' : (str_starts_with($state, 'B') ? 'success' : 'gray')),

                Tables\Columns\TextColumn::make('issue_date')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                Tables\Columns\TextColumn::make('total')
                    ->label('Total')
                    ->money('PEN')
                    ->alignment('right')
                    ->description(function (Sale $record): string {
                        $pagos = $record->payments;
                        if ($pagos->isNotEmpty()) {
                            if ($pagos->count() === 1) {
                                return ucfirst(strtolower($pagos->first()->payment_method ?? 'Efectivo'));
                            }
                            return $pagos->map(fn($p) => ucfirst(strtolower($p->payment_method)))->unique()->join(' / ');
                        }
                        return ucfirst(strtolower($record->payment_method ?? 'Efectivo'));
                    }),

                Tables\Columns\TextColumn::make('sunat_status')
                    ->label('Estado')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'pendiente' => 'warning',
                        'aceptado'  => 'success',
                        'anulado'   => 'danger',
                        'rechazado' => 'danger',
                        default     => 'gray',
                    })
                    ->description(fn (Sale $record): ?string => $record->sunat_status === 'rechazado' && $record->sunat_error_code ? "Error {$record->sunat_error_code}" : null),

                Tables\Columns\TextColumn::make('nota_asociada')
                    ->label('Nota Electrónica')
                    ->getStateUsing(function (Sale $record): ?string {
                        $partes = [];
                        $nc = SunatNoteReader::buscarNotaParaVenta($record, 'credito');
                        if ($nc) {
                            $partes[] = 'NC: ' . $nc['full_number'];
                        }
                        $nd = SunatNoteReader::buscarNotaParaVenta($record, 'debito');
                        if ($nd) {
                            $partes[] = 'ND: ' . $nd['full_number'];
                        }

                        if (!empty($partes)) {
                            return implode(' / ', $partes);
                        }

                        if ($record->sunat_status === 'anulado') {
                            return 'Sin nota de crédito';
                        }

                        return null;
                    })
                    ->badge(fn(?string $state) => !empty($state))
                    ->color(fn(?string $state): string => match (true) {
                        $state === 'Sin nota de crédito' => 'danger',
                        str_contains((string)$state, 'NC') && str_contains((string)$state, 'ND') => 'warning',
                        str_contains((string)$state, 'NC') => 'danger',
                        str_contains((string)$state, 'ND') => 'warning',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Sucursal')
                    ->badge()
                    ->color('info')
                    ->sortable()
                    ->searchable()
                    ->visible(fn () => auth()->user()?->hasRole('super_admin') ?? false),

                Tables\Columns\TextColumn::make('warehouse.name')
                    ->label('Almacén')
                    ->badge()
                    ->color('warning')
                    ->sortable()
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: false),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('branch_id')
                    ->relationship('branch', 'name')
                    ->label('Sucursal')
                    ->searchable()
                    ->preload()
                    ->visible(fn () => auth()->user()?->hasRole('super_admin') ?? false),

                Tables\Filters\SelectFilter::make('warehouse_id')
                    ->relationship('warehouse', 'name', function (\Illuminate\Database\Eloquent\Builder $query) {
                        if (!auth()->user()?->hasRole('super_admin')) {
                            $query->where('branch_id', auth()->user()?->branch_id ?? 1);
                        }
                        return $query->where('is_active', true);
                    })
                    ->label('Almacén')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('sunat_status')
                    ->label('Estado SUNAT')
                    ->options([
                        'pendiente' => 'Pendiente',
                        'aceptado'  => 'Aceptado',
                        'rechazado' => 'Rechazado',
                        'anulado'   => 'Anulado',
                    ]),

                Tables\Filters\Filter::make('con_notas')
                    ->label('Con nota de crédito o débito')
                    ->indicator('Con nota de crédito o débito')
                    ->query(function (Builder $query) {
                        return $query->where(function (Builder $q) {
                            $q->whereNotNull('credit_note_serie')
                              ->orWhereNotNull('debit_note_serie')
                              ->orWhere(function (Builder $sub) {
                                  $sub->where('sunat_status', 'anulado')
                                      ->where(function (Builder $tipo) {
                                          $tipo->where('serie', 'like', 'B%')
                                               ->orWhere('serie', 'like', 'F%');
                                      });
                              });
                        });
                    }),
            ])
            ->actions([
                // 📋 Un solo botón ⋮ con todas las acciones
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\Action::make('imprimir_ticket')
                        ->label('Imprimir Ticket')
                        ->icon('heroicon-o-printer')
                        ->color('success')
                        ->url(fn(Sale $record): string => route('sales.ticket', $record->id))
                        ->openUrlInNewTab(),

                    Tables\Actions\ViewAction::make()
                        ->label('Ver Detalles')
                        ->icon('heroicon-o-eye')
                        ->color('gray'),

                    Tables\Actions\Action::make('cambiar_pago')
                        ->label('Cambiar Pago')
                        ->icon('heroicon-o-credit-card')
                        ->color('info')
                        ->visible(fn(Sale $record) => $record->sunat_status !== 'anulado')
                        ->modalHeading(fn(Sale $record) => "Cambiar Medio de Pago - {$record->serie}-{$record->correlative}")
                        ->modalWidth('lg')
                        ->mountUsing(function (Forms\ComponentContainer $form, Sale $record) {
                            $payments = $record->payments()->get();
                            if ($payments->isEmpty()) {
                                $paymentData = [
                                    [
                                        'payment_method' => $record->payment_method ?? 'efectivo',
                                        'amount' => (float) $record->total,
                                    ]
                                ];
                            } else {
                                $paymentData = $payments->map(fn($p) => [
                                    'payment_method' => $p->payment_method,
                                    'amount' => (float) $p->amount,
                                ])->toArray();
                            }

                            $form->fill([
                                'payments' => $paymentData,
                                'amount_paid' => $record->amount_paid ?? (collect($paymentData)->where('payment_method', 'efectivo')->sum('amount') ?: $record->total),
                                'balance_returned' => $record->balance_returned ?? 0,
                            ]);
                        })
                        ->form(fn(Sale $record): array => [
                            Forms\Components\Grid::make(12)->schema([
                                Forms\Components\Placeholder::make('info_cliente')
                                    ->label('Cliente')
                                    ->content(fn() => $record->customer?->name ?? 'PÚBLICO GENERAL')
                                    ->columnSpan(7),

                                Forms\Components\Placeholder::make('info_total')
                                    ->label('Total de la Venta')
                                    ->content(fn() => 'S/ ' . number_format($record->total, 2))
                                    ->extraAttributes(['class' => 'text-xl font-bold text-primary-600 text-right'])
                                    ->columnSpan(5),

                                Forms\Components\Repeater::make('payments')
                                    ->label('Desglose de Medios de Pago')
                                    ->schema([
                                        Forms\Components\Grid::make(12)->schema([
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
                                                ->required()
                                                ->live()
                                                ->columnSpan(6),

                                            Forms\Components\TextInput::make('amount')
                                                ->label('Monto (S/)')
                                                ->numeric()
                                                ->prefix('S/')
                                                ->required()
                                                ->live(debounce: 500)
                                                ->columnSpan(6),
                                        ]),
                                    ])
                                    ->default([
                                        ['payment_method' => 'efectivo', 'amount' => $record->total]
                                    ])
                                    ->live()
                                    ->createItemButtonLabel('➕ Añadir Otro Medio de Pago')
                                    ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                        $recibido = (float) $get('amount_paid');
                                        $efectivoPagar = collect($state ?? [])
                                            ->where('payment_method', 'efectivo')
                                            ->map(fn($p) => (float)($p['amount'] ?? 0))
                                            ->sum();
                                        if ($recibido >= $efectivoPagar && $efectivoPagar > 0) {
                                            $set('balance_returned', number_format($recibido - $efectivoPagar, 2, '.', ''));
                                        } else {
                                            $set('balance_returned', '0.00');
                                        }
                                    })
                                    ->columnSpan(12),

                                Forms\Components\TextInput::make('amount_paid')
                                    ->label('Efectivo Recibido (S/)')
                                    ->numeric()
                                    ->live(debounce: 500)
                                    ->visible(fn(Get $get) => collect($get('payments') ?? [])->contains('payment_method', 'efectivo'))
                                    ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                        $efectivoPagar = collect($get('payments') ?? [])
                                            ->where('payment_method', 'efectivo')
                                            ->map(fn($p) => (float)($p['amount'] ?? 0))
                                            ->sum();
                                        $recibido = (float) $state;

                                        if ($recibido >= $efectivoPagar && $efectivoPagar > 0) {
                                            $set('balance_returned', number_format($recibido - $efectivoPagar, 2, '.', ''));
                                        } else {
                                            $set('balance_returned', '0.00');
                                        }
                                    })
                                    ->columnSpan(6),

                                Forms\Components\TextInput::make('balance_returned')
                                    ->label('Vuelto (S/)')
                                    ->numeric()
                                    ->readOnly()
                                    ->visible(fn(Get $get) => collect($get('payments') ?? [])->contains('payment_method', 'efectivo'))
                                    ->extraInputAttributes(['class' => 'bg-green-100 font-bold text-lg text-green-700 text-center'])
                                    ->columnSpan(6),
                            ]),
                        ])
                        ->action(function (array $data, Sale $record): void {
                            $rawPayments = collect($data['payments'] ?? []);
                            $totalVenta = round((float) $record->total, 2);

                            // En pagos mixtos, descartar filas con monto <= 0
                            if ($rawPayments->count() > 1) {
                                $rawPayments = $rawPayments->filter(fn($p) => (float)($p['amount'] ?? 0) > 0);
                            }

                            if ($rawPayments->count() <= 1) {
                                // Pago único: ajustar monto automáticamente al total de la venta
                                $primerPago = $rawPayments->first();
                                $metodoVenta = $primerPago['payment_method'] ?? 'efectivo';
                                $paymentsToSave = [
                                    [
                                        'payment_method' => $metodoVenta,
                                        'amount'         => $totalVenta,
                                    ]
                                ];
                            } else {
                                // Pago mixto: verificar que sumen exactamente sales.total
                                $totalPagos = round($rawPayments->sum(fn($p) => (float)($p['amount'] ?? 0)), 2);
                                if (abs($totalPagos - $totalVenta) > 0.01) {
                                    Notification::make()
                                        ->title('Error en los montos')
                                        ->body("La suma de los pagos (S/ " . number_format($totalPagos, 2) . ") debe ser igual al total de la venta (S/ " . number_format($totalVenta, 2) . ").")
                                        ->danger()
                                        ->send();
                                    return;
                                }
                                $metodoVenta = 'múltiple';
                                $paymentsToSave = $rawPayments->values()->toArray();
                            }

                            DB::beginTransaction();
                            try {
                                $previousPayments = $record->payments()->get();
                                $previousCashTotal = $previousPayments->isEmpty()
                                    ? ($record->payment_method === 'efectivo' ? (float)$record->total : 0)
                                    : (float)$previousPayments->where('payment_method', 'efectivo')->sum('amount');

                                $record->payments()->delete();

                                $newCashTotal = 0;
                                foreach ($paymentsToSave as $pago) {
                                    $method = $pago['payment_method'];
                                    $amount = (float)($pago['amount'] ?? 0);
                                    if ($method === 'efectivo') {
                                        $newCashTotal += $amount;
                                    }

                                    \App\Models\SalePayment::create([
                                        'sale_id' => $record->id,
                                        'payment_method' => $method,
                                        'amount' => $amount,
                                    ]);
                                }

                                $record->update([
                                    'payment_method'   => $metodoVenta,
                                    'amount_paid'      => (float)($data['amount_paid'] ?? $record->total),
                                    'balance_returned' => (float)($data['balance_returned'] ?? 0),
                                ]);

                                $diferenciaCaja = $newCashTotal - $previousCashTotal;
                                $cajaModificada = true;

                                if (abs($diferenciaCaja) > 0.001) {
                                    $caja = \App\Models\CashRegister::where('user_id', $record->user_id)
                                        ->where('branch_id', $record->branch_id)
                                        ->where('status', 'abierta')
                                        ->where('opened_at', '<=', $record->created_at)
                                        ->latest()
                                        ->first();

                                    if ($caja) {
                                        if ($diferenciaCaja > 0) {
                                            $caja->increment('cash_sales', $diferenciaCaja);
                                        } else {
                                            $caja->decrement('cash_sales', abs($diferenciaCaja));
                                        }
                                    } else {
                                        $cajaModificada = false;
                                    }
                                }

                                DB::commit();

                                if (!$cajaModificada) {
                                    Notification::make()
                                        ->title('Medio de pago actualizado')
                                        ->body("La caja del turno de esta venta ya está cerrada; el cambio de pago no modificó la caja.")
                                        ->warning()
                                        ->send();
                                } else {
                                    Notification::make()
                                        ->title('Medio de pago actualizado')
                                        ->body("El comprobante {$record->serie}-{$record->correlative} fue actualizado correctamente.")
                                        ->success()
                                        ->send();
                                }

                            } catch (\Exception $e) {
                                DB::rollBack();
                                Notification::make()
                                    ->title('Error al cambiar medio de pago')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),

                    Tables\Actions\Action::make('generar_guia')
                        ->label('Generar Guía')
                        ->icon('heroicon-o-truck')
                        ->color('warning')
                        ->visible(fn(Sale $record) => $record->sunat_status !== 'anulado')
                        ->url(fn(Sale $record): string => route('filament.admin.resources.despatches.create', ['sale_id' => $record->id])),

                    Tables\Actions\Action::make('imprimir_a4')
                        ->label('Imprimir A4')
                        ->icon('heroicon-o-document-text')
                        ->color('info')
                        ->url(fn(Sale $record): string => route('sales.a4', $record->id))
                        ->openUrlInNewTab()
                        ->visible(
                            fn(Sale $record) =>
                            $record->sunat_status === 'aceptado' ||
                                str_starts_with($record->serie, 'NV')
                        ),

                    Tables\Actions\Action::make('descargar_xml')
                        ->label('Descargar XML')
                        ->icon('heroicon-o-code-bracket')
                        ->color('gray')
                        ->visible(fn(Sale $record) => $record->sunat_status === 'aceptado' && !str_starts_with($record->serie, 'NV'))
                        ->action(function (Sale $record) {
                            $ruc = \App\Models\CompanySetting::first()?->ruc ?? env('EMPRESA_RUC');
                            $tipoDoc = str_starts_with($record->serie, 'F') ? '01' : '03';
                            $correlativoPadded = str_pad($record->correlative, 8, '0', STR_PAD_LEFT);
                            $nombreArchivo = "{$ruc}-{$tipoDoc}-{$record->serie}-{$correlativoPadded}";
                            $ruta = storage_path("app/sunat/xmls/{$nombreArchivo}.xml");

                            if (file_exists($ruta)) {
                                return response()->download($ruta);
                            }
                            Notification::make()->title('XML no encontrado en el servidor')->danger()->send();
                        }),

                    Tables\Actions\Action::make('ver_cdr')
                        ->label('Descargar CDR')
                        ->icon('heroicon-o-shield-check')
                        ->color('success')
                        ->visible(fn(Sale $record) => $record->sunat_status === 'aceptado' && !str_starts_with($record->serie, 'NV'))
                        ->action(function (Sale $record) {
                            $ruc = \App\Models\CompanySetting::first()?->ruc ?? env('EMPRESA_RUC');
                            $tipoDoc = str_starts_with($record->serie, 'F') ? '01' : '03';
                            $correlativoPadded = str_pad($record->correlative, 8, '0', STR_PAD_LEFT);
                            $nombreArchivo = "{$ruc}-{$tipoDoc}-{$record->serie}-{$correlativoPadded}";
                            $ruta = storage_path("app/sunat/cdrs/R-{$nombreArchivo}.zip");

                            if (file_exists($ruta)) {
                                return response()->download($ruta);
                            }
                            Notification::make()->title('CDR no encontrado en el servidor')->danger()->send();
                        }),

                    // 🌟 ACCIONES DE NOTA DE CRÉDITO
                    Tables\Actions\Action::make('imprimir_ticket_nc')
                        ->label('Imprimir Ticket NC')
                        ->icon('heroicon-o-printer')
                        ->color('danger')
                        ->url(fn(Sale $record): string => route('sales.notes.ticket', ['sale' => $record->id, 'tipo' => 'credito']))
                        ->openUrlInNewTab()
                        ->visible(fn(Sale $record) => SunatNoteReader::buscarNotaParaVenta($record, 'credito') !== null),

                    Tables\Actions\Action::make('imprimir_a4_nc')
                        ->label('Imprimir A4 NC')
                        ->icon('heroicon-o-document-text')
                        ->color('danger')
                        ->url(fn(Sale $record): string => route('sales.notes.a4', ['sale' => $record->id, 'tipo' => 'credito']))
                        ->openUrlInNewTab()
                        ->visible(fn(Sale $record) => SunatNoteReader::buscarNotaParaVenta($record, 'credito') !== null),

                    Tables\Actions\Action::make('descargar_xml_nc')
                        ->label('Descargar XML NC')
                        ->icon('heroicon-o-code-bracket')
                        ->color('gray')
                        ->url(fn(Sale $record): string => route('sales.notes.xml', ['sale' => $record->id, 'tipo' => 'credito']))
                        ->visible(fn(Sale $record) => SunatNoteReader::buscarNotaParaVenta($record, 'credito') !== null),

                    Tables\Actions\Action::make('descargar_cdr_nc')
                        ->label('Descargar CDR NC')
                        ->icon('heroicon-o-shield-check')
                        ->color('success')
                        ->url(fn(Sale $record): string => route('sales.notes.cdr', ['sale' => $record->id, 'tipo' => 'credito']))
                        ->visible(fn(Sale $record) => SunatNoteReader::buscarNotaParaVenta($record, 'credito') !== null),

                    // 🌟 ACCIONES DE NOTA DE DÉBITO
                    Tables\Actions\Action::make('imprimir_ticket_nd')
                        ->label('Imprimir Ticket ND')
                        ->icon('heroicon-o-printer')
                        ->color('warning')
                        ->url(fn(Sale $record): string => route('sales.notes.ticket', ['sale' => $record->id, 'tipo' => 'debito']))
                        ->openUrlInNewTab()
                        ->visible(fn(Sale $record) => SunatNoteReader::buscarNotaParaVenta($record, 'debito') !== null),

                    Tables\Actions\Action::make('imprimir_a4_nd')
                        ->label('Imprimir A4 ND')
                        ->icon('heroicon-o-document-text')
                        ->color('warning')
                        ->url(fn(Sale $record): string => route('sales.notes.a4', ['sale' => $record->id, 'tipo' => 'debito']))
                        ->openUrlInNewTab()
                        ->visible(fn(Sale $record) => SunatNoteReader::buscarNotaParaVenta($record, 'debito') !== null),

                    Tables\Actions\Action::make('descargar_xml_nd')
                        ->label('Descargar XML ND')
                        ->icon('heroicon-o-code-bracket')
                        ->color('gray')
                        ->url(fn(Sale $record): string => route('sales.notes.xml', ['sale' => $record->id, 'tipo' => 'debito']))
                        ->visible(fn(Sale $record) => SunatNoteReader::buscarNotaParaVenta($record, 'debito') !== null),

                    Tables\Actions\Action::make('descargar_cdr_nd')
                        ->label('Descargar CDR ND')
                        ->icon('heroicon-o-shield-check')
                        ->color('success')
                        ->url(fn(Sale $record): string => route('sales.notes.cdr', ['sale' => $record->id, 'tipo' => 'debito']))
                        ->visible(fn(Sale $record) => SunatNoteReader::buscarNotaParaVenta($record, 'debito') !== null),

                    // 🌟 ACCIÓN DE REGULARIZACIÓN DE NOTA DE CRÉDITO
                    Tables\Actions\Action::make('regularizar_nc')
                        ->label('Regularizar Nota de Crédito')
                        ->icon('heroicon-o-arrow-path-rounded-square')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading('Regularizar Nota de Crédito en SUNAT')
                        ->modalDescription('Esta venta figura anulada en el sistema pero NO tiene Nota de Crédito en SUNAT. Se emitirá la Nota de Crédito oficial ante SUNAT para anular tributariamente el comprobante. El inventario NO se devolverá de nuevo porque ya fue devuelto en la anulación previa.')
                        ->form([
                            Forms\Components\Select::make('codigo_motivo')
                                ->label('Motivo Oficial SUNAT')
                                ->options([
                                    '01' => 'Anulación de la operación',
                                    '02' => 'Anulación por error en el RUC/DNI',
                                    '06' => 'Devolución total',
                                ])
                                ->default('01')
                                ->required(),
                            Forms\Components\Textarea::make('motivo_texto')
                                ->label('Detalle / Observación')
                                ->placeholder('Ej. Regularización de anulación previa')
                                ->maxLength(250),
                        ])
                        ->visible(fn(Sale $record) =>
                            !str_starts_with($record->serie, 'NV') &&
                            $record->sunat_status === 'anulado' &&
                            \App\Services\SunatNoteReader::buscarNotaParaVenta($record, 'credito') === null
                        )
                        ->action(function (Sale $record, array $data) {
                            $res = \App\Services\SaleAnulacionService::regularizarNotaCredito($record, $data, auth()->user());
                            if (!isset($res['success']) || $res['success'] !== true) {
                                Notification::make()
                                    ->title('Error al regularizar')
                                    ->body($res['error'] ?? 'No se pudo emitir la Nota de Crédito.')
                                    ->danger()
                                    ->persistent()
                                    ->send();
                                return;
                            }

                            Notification::make()
                                ->title($res['title'])
                                ->body($res['message'])
                                ->success()
                                ->actions([
                                    \Filament\Notifications\Actions\Action::make('ticket')
                                        ->label('Ticket NC')
                                        ->url(route('sales.notes.ticket', ['sale' => $record->id, 'tipo' => 'credito']), true),
                                    \Filament\Notifications\Actions\Action::make('a4')
                                        ->label('A4 NC')
                                        ->url(route('sales.notes.a4', ['sale' => $record->id, 'tipo' => 'credito']), true),
                                ])
                                ->persistent()
                                ->send();
                        }),

                    Tables\Actions\Action::make('declarar_sunat')
                        ->label('Declarar a SUNAT')
                        ->icon('heroicon-o-cloud-arrow-up')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->visible(
                            fn(Sale $record) =>
                            !str_starts_with($record->serie, 'NV') &&
                                $record->sunat_status === 'pendiente' &&
                                (!$record->is_quick_sale || $record->is_audited)
                        )
                        ->action(function (Sale $record) {
                            try {
                                $service = new \App\Services\SunatService();
                                $res = $service->enviarBoleta($record, isRetry: true);

                                if (isset($res['success'])) {
                                    $record->update([
                                        'sunat_status'     => 'aceptado',
                                        'sunat_error'      => null,
                                        'sunat_error_code' => null,
                                    ]);
                                    Notification::make()->title('¡Documento Aceptado por SUNAT!')->success()->send();
                                } elseif (!empty($res['rejected_by_sunat'])) {
                                    $record->update([
                                        'sunat_status'     => 'rechazado',
                                        'sunat_error'      => $res['error'] ?? 'Rechazado por SUNAT',
                                        'sunat_error_code' => $res['code'] ?? null,
                                    ]);
                                    Notification::make()
                                        ->title('Comprobante Rechazado por SUNAT')
                                        ->body(($res['error'] ?? 'Rechazado') . '. Debe ser reemitido por un administrador.')
                                        ->danger()
                                        ->persistent()
                                        ->send();
                                } else {
                                    $record->update([
                                        'sunat_error'      => $res['error'] ?? 'Ocurrió un error inesperado',
                                        'sunat_error_code' => $res['code'] ?? null,
                                    ]);
                                    Notification::make()->title('Error SUNAT')->body($res['error'] ?? 'Ocurrió un error inesperado')->danger()->send();
                                }
                            } catch (\Exception $e) {
                                Notification::make()
                                    ->title('Error de Conexión SUNAT')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),

                    Tables\Actions\Action::make('reemitir_comprobante')
                        ->label('Reemitir Comprobante')
                        ->icon('heroicon-o-arrow-path')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading(fn (Sale $record) => "Reemitir Comprobante {$record->serie}-" . str_pad($record->correlative, 8, '0', STR_PAD_LEFT))
                        ->modalDescription(fn (Sale $record) => $record->sunat_status === 'pendiente'
                            ? 'Este comprobante está pendiente. Se reintentará primero con su número original. Si SUNAT lo rechaza, se reemitirá con un nuevo correlativo y fecha de hoy.'
                            : 'Este comprobante fue rechazado por SUNAT. Se asignará un nuevo correlativo de la serie con fecha de hoy y se enviará a SUNAT. El número anterior quedará guardado en el historial. Stock y caja no se modificarán.'
                        )
                        ->modalSubmitActionLabel('Confirmar y Procesar')
                        ->visible(fn (Sale $record) =>
                            auth()->user()?->hasRole('super_admin') &&
                            !str_starts_with($record->serie, 'NV') &&
                            in_array($record->sunat_status, ['rechazado', 'pendiente']) &&
                            (!$record->is_quick_sale || $record->is_audited)
                        )
                        ->action(function (Sale $record) {
                            $service = new \App\Services\SaleReemissionService();
                            $res = $service->reemitirOReintentar($record, auth()->user());

                            if (!empty($res['success'])) {
                                Notification::make()
                                    ->title('Comprobante Procesado con Éxito')
                                    ->body($res['message'])
                                    ->success()
                                    ->persistent()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('Atención al procesar comprobante')
                                    ->body($res['error'] ?? 'Ocurrió un error al procesar la reemisión.')
                                    ->danger()
                                    ->persistent()
                                    ->send();
                            }
                        }),

                    Tables\Actions\Action::make('auditar_venta')
                        ->label('Auditar Venta')
                        ->icon('heroicon-o-magnifying-glass-circle')
                        ->color('danger')
                        ->visible(fn(Sale $record) => $record->is_quick_sale && !$record->is_audited)
                        ->form(fn (Sale $record): array => [
                            Forms\Components\Placeholder::make('quick_sale_total_info')
                                ->label('Total Cobrado Original')
                                ->content(fn() => 'S/ ' . number_format($record->total, 2)),
                            
                            Forms\Components\Repeater::make('audited_items')
                                ->label('Prendas reales vendidas')
                                ->required()
                                ->minItems(1)
                                ->schema([
                                    Forms\Components\Grid::make(12)->schema([
                                        Forms\Components\Select::make('product_variant_id')
                                            ->label('Prenda')
                                            ->searchable()
                                            ->getSearchResultsUsing(function (string $search) use ($record) {
                                                $warehouseId = $record->warehouse_id ?? $record->branch?->defaultWarehouse?->id ?? 1;
                                                $branchId = $record->branch_id ?? 1;
                                                return \App\Models\ProductVariant::with('product')
                                                    ->whereHas('product', fn($q) => $q->where('name', 'like', "%{$search}%")->whereHas('branches', fn($b) => $b->where('branches.id', $branchId)))
                                                    ->whereHas('inventories', fn($q) => $q->where('warehouse_id', $warehouseId)->where('stock', '>', 0))
                                                    ->limit(10)
                                                    ->get()
                                                    ->mapWithKeys(fn($v) => [$v->id => $v->product->name . ' - ' . ($v->sku ?? $v->id)]);
                                            })
                                            ->getOptionLabelUsing(function ($value) {
                                                $v = \App\Models\ProductVariant::with('product')->find($value);
                                                return $v ? ($v->product?->name . ' - ' . ($v->sku ?? $v->id)) : null;
                                            })
                                            ->required()
                                            ->live()
                                            ->columnSpan(6),

                                        Forms\Components\TextInput::make('quantity')
                                            ->label('Cantidad')
                                            ->numeric()
                                            ->default(1)
                                            ->minValue(1)
                                            ->required()
                                            ->live(debounce: 500)
                                            ->columnSpan(3),

                                        Forms\Components\TextInput::make('price')
                                            ->label('Precio Unit. (S/)')
                                            ->numeric()
                                            ->prefix('S/')
                                            ->required()
                                            ->live(debounce: 500)
                                            ->columnSpan(3),
                                    ])
                                ])
                                ->live()
                                ->createItemButtonLabel('➕ Agregar prenda')
                        ])
                        ->action(function (Sale $record, array $data) {
                            if (!$record->is_quick_sale || $record->is_audited) {
                                Notification::make()
                                    ->title('Venta ya auditada')
                                    ->body('Esta venta rápida ya fue auditada anteriormente. No se puede volver a auditar ni descontar el stock dos veces.')
                                    ->danger()
                                    ->persistent()
                                    ->send();
                                return;
                            }

                            $items = $data['audited_items'] ?? [];
                            $esElectronico = !str_starts_with(trim((string)$record->serie), 'NV');

                            if ($esElectronico) {
                                foreach ($items as $item) {
                                    $precio = (float)($item['price'] ?? 0);
                                    $cant = (float)($item['quantity'] ?? 1);
                                    if ($precio <= 0 || ($precio * $cant) <= 0) {
                                        Notification::make()
                                            ->title('Línea con precio 0 no permitida')
                                            ->body('Las boletas y facturas no admiten líneas con precio 0. Escribe la placa u observación en la descripción del producto.')
                                            ->danger()
                                            ->persistent()
                                            ->send();
                                        return;
                                    }
                                }
                            }
                            
                            $totalRealSum = 0;
                            foreach ($items as $item) {
                                $totalRealSum += (float)($item['price'] ?? 0) * (int)($item['quantity'] ?? 1);
                            }
                            $totalRealSum = round($totalRealSum, 2);
                            $totalCobrado = round((float)($record->quick_sale_total ?: $record->total), 2);

                            if (abs($totalRealSum - $totalCobrado) > 0.01) {
                                $diferencia = round($totalRealSum - $totalCobrado, 2);
                                $sentido = $diferencia > 0 ? 'de más' : 'de menos';
                                Notification::make()
                                    ->title('El total auditado no coincide con el total cobrado')
                                    ->body('La suma de las líneas auditadas (S/ ' . number_format($totalRealSum, 2) . ') debe ser igual al total cobrado en la venta rápida (S/ ' . number_format($totalCobrado, 2) . '). Diferencia: S/ ' . number_format(abs($diferencia), 2) . " {$sentido}.")
                                    ->danger()
                                    ->persistent()
                                    ->send();
                                return;
                            }

                            DB::beginTransaction();
                            try {
                                $lockedRecord = Sale::where('id', $record->id)->lockForUpdate()->first();
                                if (!$lockedRecord || $lockedRecord->is_audited) {
                                    DB::rollBack();
                                    Notification::make()
                                        ->title('Venta ya auditada')
                                        ->body('Esta venta rápida ya fue auditada anteriormente. No se puede descontar el stock dos veces.')
                                        ->danger()
                                        ->persistent()
                                        ->send();
                                    return;
                                }

                                $warehouseId = $record->warehouse_id ?? $record->branch?->defaultWarehouse?->id ?? 1;

                                // Salvaguarda: si existieran líneas previas con stock antes de borrar, devolver su stock primero
                                foreach ($record->items()->with('productVariant.product')->get() as $prevItem) {
                                    $prevVariant = $prevItem->productVariant;
                                    $prevIsService = ($prevVariant?->product?->is_service) || ($prevVariant?->sku === 'REPE-GENERICO');
                                    if ($prevVariant && !$prevIsService) {
                                        $prevInv = \App\Models\Inventory::where('product_variant_id', $prevItem->product_variant_id)
                                            ->where('warehouse_id', $warehouseId)
                                            ->lockForUpdate()
                                            ->first();
                                        if ($prevInv) {
                                            $prevInv->increment('stock', (int)$prevItem->quantity);
                                        }
                                    }
                                }

                                $record->items()->delete();
                                $hasSubtotalColumn = \Illuminate\Support\Facades\Schema::hasColumn('sale_items', 'subtotal');

                                foreach ($items as $item) {
                                    $variant = \App\Models\ProductVariant::with('product')->find($item['product_variant_id']);
                                    $productName = !empty($item['product_name'])
                                        ? $item['product_name']
                                        : (($variant && $variant->product) ? $variant->product->name : 'Prenda');
                                    $qty = (int)($item['quantity'] ?? 1);
                                    $price = (float)($item['price'] ?? 0);
                                    $lineTotal = round($price * $qty, 2);

                                    $itemPayload = [
                                        'sale_id'            => $record->id,
                                        'product_variant_id' => $item['product_variant_id'],
                                        'product_name'       => $productName,
                                        'quantity'           => $qty,
                                        'price'              => $price,
                                        'igv'                => round($lineTotal - ($lineTotal / 1.18), 2),
                                        'total'              => $lineTotal,
                                    ];
                                    if ($hasSubtotalColumn) {
                                        $itemPayload['subtotal'] = round($lineTotal / 1.18, 2);
                                    }

                                    \App\Models\SaleItem::create($itemPayload);

                                    $isService = ($variant?->product?->is_service) || ($variant?->sku === 'REPE-GENERICO');
                                    if (!$isService) {
                                        $inventario = \App\Models\Inventory::where('product_variant_id', $item['product_variant_id'])
                                            ->where('warehouse_id', $warehouseId)
                                            ->lockForUpdate()
                                            ->first();
                                        if (!$inventario) {
                                            $inventario = \App\Models\Inventory::firstOrCreate(
                                                [
                                                    'product_variant_id' => $item['product_variant_id'],
                                                    'warehouse_id' => $warehouseId,
                                                ],
                                                [
                                                    'branch_id' => $record->branch_id,
                                                    'stock' => 0,
                                                ]
                                            );
                                            $inventario = \App\Models\Inventory::where('id', $inventario->id)->lockForUpdate()->first();
                                        }
                                        if ($inventario) {
                                            $inventario->decrement('stock', $qty);
                                        }
                                    }
                                }

                                $subtotal = round($totalRealSum / 1.18, 2);
                                $igv = round($totalRealSum - $subtotal, 2);

                                $record->update([
                                    'warehouse_id' => $warehouseId,
                                    'total'        => $totalRealSum,
                                    'subtotal'     => $subtotal,
                                    'igv'          => $igv,
                                    'is_audited'   => true,
                                ]);

                                DB::commit();

                                $record->unsetRelation('items');
                                $record->load(['branch', 'items', 'customer']);

                                Notification::make()
                                    ->title('Venta Auditada Exitosamente')
                                    ->body('El inventario ha sido descontado y los items actualizados.')
                                    ->success()
                                    ->send();

                                if (!str_starts_with($record->serie, 'NV')) {
                                    try {
                                        $service = new \App\Services\SunatService();
                                        $res = $service->enviarBoleta($record);
                                        if (isset($res['success']) && $res['success'] === true) {
                                            $record->update([
                                                'sunat_status'     => 'aceptado',
                                                'sunat_error'      => null,
                                                'sunat_error_code' => null,
                                            ]);
                                            Notification::make()->title('¡Venta Aceptada por SUNAT!')->success()->send();
                                        } else {
                                            $isRejected = !empty($res['rejected_by_sunat']);
                                            $record->update([
                                                'sunat_status'     => $isRejected ? 'rechazado' : 'pendiente',
                                                'sunat_error'      => $res['error'] ?? null,
                                                'sunat_error_code' => $res['code'] ?? null,
                                            ]);
                                            Notification::make()->title('Venta auditada. Falló envío a SUNAT')->body($res['error'] ?? 'Pendiente de envío manual.')->warning()->send();
                                        }
                                    } catch (\Exception $e) {
                                        Notification::make()->title('Venta auditada. Sin conexión a SUNAT')->warning()->send();
                                    }
                                }

                            } catch (\Exception $e) {
                                DB::rollBack();
                                Notification::make()
                                    ->title('Error al procesar la auditoría')
                                    ->body($e->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),
                ])
                ->icon('heroicon-m-ellipsis-vertical')
                ->color('gray')
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('reemitir_lote')
                        ->label('Reemitir / Reintentar Seleccionados')
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading('Reemisión y Reintento Masivo a SUNAT')
                        ->modalDescription('Se procesarán los comprobantes seleccionados que estén pendientes o rechazados. Los pendientes se reintentarán primero; si SUNAT los rechaza, se reemitirán con número nuevo. Los rechazados se reemitirán directamente con número nuevo y fecha de hoy. Stock y caja no se modificarán.')
                        ->modalSubmitActionLabel('Iniciar Procesamiento Masivo')
                        ->visible(fn () => auth()->user()?->hasRole('super_admin'))
                        ->action(function (\Illuminate\Database\Eloquent\Collection $records) {
                            $filtrados = $records->filter(fn (Sale $r) =>
                                !str_starts_with($r->serie, 'NV') &&
                                in_array($r->sunat_status, ['rechazado', 'pendiente']) &&
                                (!$r->is_quick_sale || $r->is_audited)
                            );

                            if ($filtrados->isEmpty()) {
                                Notification::make()
                                    ->title('Sin comprobantes aptos')
                                    ->body('Ninguno de los registros seleccionados califica para reemisión o reintento.')
                                    ->warning()
                                    ->send();
                                return;
                            }

                            $service = new \App\Services\SaleReemissionService();
                            $res = $service->reemitirLote($filtrados, auth()->user());

                            $msg = "Total procesados: {$res['total']}. "
                                . "Aceptados sin reemitir: " . ($res['marked_accepted'] + $res['retry_accepted']) . ". "
                                . "Reemitidos y Aceptados: {$res['reemitted_accepted']}. "
                                . "Reemitidos pendientes (reintento red): {$res['reemitted_pending']}. "
                                . "Rechazados: {$res['reemitted_rejected']}. "
                                . "Fallidos: {$res['failed']}.";

                            Notification::make()
                                ->title('Proceso Masivo Finalizado')
                                ->body($msg)
                                ->persistent()
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Auth::user();

        if ($user && !$user->hasRole('super_admin')) {
            $query->where('branch_id', $user->branch_id ?? 1);
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSales::route('/'),
            'create' => Pages\CreateSale::route('/create'),
            'view' => Pages\ViewSale::route('/{record}'),
        ];
    }
}
