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
    protected static ?string $navigationGroup = 'Ventas y cajas'; 
    protected static ?int $navigationSort = 2; 
    protected static ?string $modelLabel = 'Venta';

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
                                    ->live()
                                    ->afterStateUpdated(function (Get $get, Set $set) {
                                        $set('wholesale_request_id', null);
                                        \App\Support\SaleCart::recalc($get, $set);
                                    })
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
                                                if ($user && $user->seesAllBranches()) {
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
                                        if ($user && $user->seesAllBranches()) {
                                            $hoyLima = now()->setTimezone('America/Lima')->startOfDay();
                                            if ($user->branch_id && \App\Models\CashRegister::where('branch_id', $user->branch_id)->where('status', 'abierta')->where('opened_at', '>=', $hoyLima)->exists()) {
                                                return $user->branch_id;
                                            }
                                            return \App\Models\CashRegister::where('status', 'abierta')->where('opened_at', '>=', $hoyLima)->value('branch_id');
                                        }
                                        return Auth::user()?->branch_id;
                                    })
                                    ->disabled(fn () => !Auth::user()?->seesAllBranches())
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
                                    ->disabled(fn () => !Auth::user()?->seesAllBranches())
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
                    ->key('detalle')
                    ->visible(fn(Get $get) => !$get('is_quick_sale'))
                    ->headerActions([
                        // Lectora de código de barras: escanear agrega la prenda (o suma 1 si ya está)
                        Forms\Components\Actions\Action::make('scan')
                            ->label('Escanear / código')
                            ->icon('heroicon-o-qr-code')
                            ->color('gray')
                            ->modalHeading('Escanear prenda')
                            ->modalSubmitActionLabel('Agregar')
                            ->form([
                                Forms\Components\TextInput::make('code')
                                    ->label('Código de barras o SKU')
                                    ->autofocus()
                                    ->required(),
                                Forms\Components\TextInput::make('quantity')
                                    ->label('Cantidad')
                                    ->numeric()->minValue(1)->default(1)->required(),
                            ])
                            ->action(function (array $data, Get $get, Set $set) {
                                $variant = \App\Support\SaleCart::findByCode($data['code']);
                                if (!$variant || $variant->product?->is_service) {
                                    Notification::make()->title('No se encontró una prenda con ese código')->danger()->send();
                                    return;
                                }
                                $warehouseId = \App\Support\SaleCart::warehouseFor($get('branch_id'), $get('warehouse_id'));
                                $items = collect($get('items') ?? [])->filter(fn ($i) => !empty($i['product_variant_id']))->all();
                                $qty = (int) $data['quantity'];
                                $existingKey = collect($items)->search(fn ($i) => (int) $i['product_variant_id'] === $variant->id);
                                $inCart = $existingKey !== false ? (int) $items[$existingKey]['quantity'] : 0;
                                $stock = $variant->stockIn($warehouseId);
                                if ($inCart + $qty > $stock) {
                                    Notification::make()->title('Stock insuficiente')
                                        ->body("{$variant->display_name}: hay {$stock} en este almacén.")->danger()->send();
                                    return;
                                }
                                if ($existingKey !== false) {
                                    $items[$existingKey]['quantity'] = $inCart + $qty;
                                } else {
                                    $items[(string) \Illuminate\Support\Str::uuid()] = [
                                        'product_variant_id' => $variant->id,
                                        'quantity' => $qty,
                                        'product_name' => $variant->display_name,
                                        'price' => 0,
                                    ];
                                }
                                $set('items', $items);
                                \App\Support\SaleCart::recalc($get, $set);
                            }),

                        // Selector visual: modelo → color → talla (con el stock de cada una)
                        Forms\Components\Actions\Action::make('pick_model')
                            ->label('Elegir por modelo')
                            ->icon('heroicon-o-swatch')
                            ->color('primary')
                            ->modalHeading('Elegir prenda por modelo, color y talla')
                            ->modalSubmitActionLabel('Agregar a la venta')
                            ->form(function (Get $get) {
                                $branchId = $get('branch_id');
                                $warehouseId = \App\Support\SaleCart::warehouseFor($branchId, $get('warehouse_id'));

                                return [
                                    Forms\Components\Select::make('product_id')
                                        ->label('Modelo')
                                        ->searchable()
                                        ->getSearchResultsUsing(fn (string $search) => \App\Models\Product::query()
                                            ->where('is_service', false)
                                            ->whereHas('branches', fn ($b) => $b->where('branches.id', $branchId))
                                            ->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"))
                                            ->limit(30)->get()
                                            ->mapWithKeys(fn ($p) => [$p->id => $p->name . ' · S/ ' . number_format((float) $p->sale_price, 2)]))
                                        ->getOptionLabelUsing(fn ($value) => \App\Models\Product::find($value)?->name)
                                        ->required()
                                        ->live()
                                        ->afterStateUpdated(function (Set $set) {
                                            $set('color', null);
                                            $set('variant_id', null);
                                        }),
                                    Forms\Components\ToggleButtons::make('color')
                                        ->label('Color')
                                        ->inline()
                                        ->visible(fn (Get $get) => filled($get('product_id')))
                                        ->options(fn (Get $get) => \App\Models\ProductVariant::where('product_id', $get('product_id'))
                                            ->whereNotNull('color_name')->orderBy('color_id')->pluck('color_name', 'color_name')->unique()->all())
                                        ->required()
                                        ->live()
                                        ->afterStateUpdated(fn (Set $set) => $set('variant_id', null)),
                                    Forms\Components\ToggleButtons::make('variant_id')
                                        ->label('Talla (stock en este almacén)')
                                        ->inline()
                                        ->visible(fn (Get $get) => filled($get('color')))
                                        ->options(fn (Get $get) => \App\Models\ProductVariant::with('sizeOption')
                                            ->where('product_id', $get('product_id'))->where('color_name', $get('color'))
                                            ->get()->sortBy(fn ($v) => $v->sizeOption?->sort_order ?? 999)
                                            ->mapWithKeys(fn ($v) => [$v->id => $v->size . ' (' . $v->stockIn($warehouseId) . ')'])
                                            ->all())
                                        ->disableOptionWhen(fn (string $value) => (\App\Models\ProductVariant::find($value)?->stockIn($warehouseId) ?? 0) <= 0)
                                        ->helperText('Las tallas en gris no tienen stock en este almacén.')
                                        ->required(),
                                    Forms\Components\TextInput::make('quantity')->label('Cantidad')->numeric()->minValue(1)->default(1)->required(),
                                ];
                            })
                            ->action(function (array $data, Get $get, Set $set) {
                                $variant = \App\Models\ProductVariant::with('product')->find($data['variant_id']);
                                if (!$variant) {
                                    return;
                                }
                                $warehouseId = \App\Support\SaleCart::warehouseFor($get('branch_id'), $get('warehouse_id'));
                                $items = collect($get('items') ?? [])->filter(fn ($i) => !empty($i['product_variant_id']))->all();
                                $existingKey = collect($items)->search(fn ($i) => (int) $i['product_variant_id'] === $variant->id);
                                $inCart = $existingKey !== false ? (int) $items[$existingKey]['quantity'] : 0;
                                $qty = (int) $data['quantity'];
                                if ($inCart + $qty > $variant->stockIn($warehouseId)) {
                                    Notification::make()->title('Stock insuficiente')
                                        ->body("{$variant->display_name}: hay {$variant->stockIn($warehouseId)} en este almacén.")->danger()->send();

                                    return;
                                }
                                if ($existingKey !== false) {
                                    $items[$existingKey]['quantity'] = $inCart + $qty;
                                } else {
                                    $items[(string) \Illuminate\Support\Str::uuid()] = [
                                        'product_variant_id' => $variant->id,
                                        'quantity' => $qty,
                                        'product_name' => $variant->display_name,
                                        'price' => 0,
                                    ];
                                }
                                $set('items', $items);
                                \App\Support\SaleCart::recalc($get, $set);
                            }),

                        // Delivery: solo si alguna prenda del detalle lo permite (decisión 4)
                        Forms\Components\Actions\Action::make('add_service')
                            ->label('Agregar delivery')
                            ->icon('heroicon-o-truck')
                            ->color('warning')
                            ->form([
                                Forms\Components\Select::make('product_variant_id')
                                    ->label('Servicio')
                                    ->options(fn () => \App\Models\ProductVariant::whereHas('product', fn ($q) => $q->where('is_service', true))
                                        ->with('product')->get()
                                        ->mapWithKeys(fn ($v) => [$v->id => '🚚 ' . ($v->product?->name ?? 'Delivery')]))
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(fn ($state, Set $set) => $set('price', \App\Models\ProductVariant::find($state)?->retail_price ?? 0)),
                                Forms\Components\TextInput::make('price')
                                    ->label('Precio del delivery')
                                    ->numeric()->minValue(0.01)->prefix('S/')->required(),
                            ])
                            ->action(function (array $data, Get $get, Set $set) {
                                $items = collect($get('items') ?? [])->filter(fn ($i) => !empty($i['product_variant_id']))->all();
                                if (!\App\Support\SaleCart::allowsDelivery($items)) {
                                    Notification::make()->title('Delivery no disponible')
                                        ->body('Ninguna prenda de esta venta permite delivery. Se habilita en cada modelo ("Permite delivery").')
                                        ->danger()->send();
                                    return;
                                }
                                $variant = \App\Models\ProductVariant::with('product')->find($data['product_variant_id']);
                                $items[(string) \Illuminate\Support\Str::uuid()] = [
                                    'product_variant_id' => $variant->id,
                                    'quantity' => 1,
                                    'price' => (float) $data['price'],
                                    'product_name' => $variant->product?->name ?? 'Delivery',
                                ];
                                $set('items', $items);
                                \App\Support\SaleCart::recalc($get, $set);
                            }),
                    ])
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
                            ->hidden(fn(Get $get) => (bool)$get('is_quick_sale'))
                            ->defaultItems(1)
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => \App\Support\SaleCart::recalc($get, $set))
                            ->deleteAction(fn ($action) => $action->after(fn (Get $get, Set $set) => \App\Support\SaleCart::recalc($get, $set)))
                            ->schema([
                                Forms\Components\Grid::make(12)
                                    ->schema([
                                        Forms\Components\Select::make('product_variant_id')
                                            ->label('Prenda (modelo / color / talla)')
                                            ->searchable()
                                            ->getSearchResultsUsing(fn (string $search, Get $get) => \App\Support\SaleCart::options(
                                                $search,
                                                $get('../../branch_id') ? (int) $get('../../branch_id') : null,
                                                $get('../../warehouse_id') ? (int) $get('../../warehouse_id') : null,
                                            ))
                                            ->getOptionLabelUsing(fn ($value, Get $get) => \App\Support\SaleCart::optionLabel(
                                                $value,
                                                \App\Support\SaleCart::warehouseFor($get('../../branch_id'), $get('../../warehouse_id')),
                                            ))
                                            ->required()
                                            ->live()
                                            ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                                $variant = \App\Models\ProductVariant::with('product')->find($state);
                                                $set('product_name', $variant?->product?->is_service ? ($variant->product->name) : ($variant?->display_name ?? ''));
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
                                            ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                                $variantId = $get('product_variant_id');
                                                if ($variantId) {
                                                    $warehouseId = \App\Support\SaleCart::warehouseFor($get('../../branch_id'), $get('../../warehouse_id'));
                                                    $variant = \App\Models\ProductVariant::with('product')->find($variantId);
                                                    if ($variant && !$variant->product?->is_service) {
                                                        $inCart = collect($get('../../items') ?? [])
                                                            ->where('product_variant_id', $variantId)
                                                            ->sum(fn ($i) => (int) ($i['quantity'] ?? 0));
                                                        $stock = $variant->stockIn($warehouseId);
                                                        if ($inCart > $stock) {
                                                            Notification::make()
                                                                ->title('Stock insuficiente')
                                                                ->body("{$variant->display_name}: hay {$stock} en este almacén y en la venta van {$inCart}. No se podrá guardar así.")
                                                                ->danger()
                                                                ->send();
                                                        }
                                                    }
                                                }
                                                \App\Support\SaleCart::recalc($get, $set, '../../');
                                            })
                                            ->columnSpan(2),

                                        Forms\Components\TextInput::make('price')
                                            ->label('Precio (S/)')
                                            ->numeric()
                                            ->required()
                                            // Solo el administrador cambia precios a mano (decisión 2)
                                            ->readOnly(fn () => !Auth::user()?->can('edit_sale_price'))
                                            ->helperText(fn (Get $get) => match ($get('price_reason')) {
                                                'manual' => 'Precio a mano',
                                                'authorized' => 'Mayorista aprobado',
                                                'quantity' => 'Mayorista por cantidad',
                                                default => $get('price_type') === 'service' ? 'Servicio' : null,
                                            })
                                            ->live(onBlur: true)
                                            ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                                if (Auth::user()?->can('edit_sale_price')) {
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
                                            ->label('Descripción en el comprobante')
                                            ->required()
                                            ->readOnly()
                                            ->placeholder('Se completa al elegir la prenda')
                                            ->columnSpan(12),

                                        Forms\Components\Hidden::make('igv'),
                                        Forms\Components\Hidden::make('list_price'),
                                        Forms\Components\Hidden::make('price_type'),
                                        Forms\Components\Hidden::make('price_reason'),
                                    ]),
                            ]),
                    ]),

                // ==========================================
                // CAMBIO DE TALLA / COLOR: saldo a favor de la nota de crédito
                // ==========================================
                Forms\Components\Section::make('Cambio de talla o color')
                    ->icon('heroicon-o-arrows-right-left')
                    ->visible(fn (Get $get) => filled($get('exchange_return_id')))
                    ->schema([
                        Forms\Components\Hidden::make('exchange_return_id')->dehydrated(false),
                        Forms\Components\Placeholder::make('exchange_info')
                            ->hiddenLabel()
                            ->content(function (Get $get) {
                                $return = \App\Models\SaleReturn::with('sale')->find($get('exchange_return_id'));
                                if (!$return) {
                                    return '';
                                }
                                $credit = (float) $return->available_credit;
                                $total = (float) collect($get('items') ?? [])->sum(fn ($i) => (float) ($i['total'] ?? 0));
                                $nc = $return->credit_note_number ? " (nota de crédito {$return->credit_note_number})" : '';

                                return new \Illuminate\Support\HtmlString(
                                    '<div class="text-base"><strong>Saldo a favor: S/ ' . number_format($credit, 2) . '</strong>' . e($nc)
                                    . ' · cambio de ' . e($return->sale?->serie . '-' . $return->sale?->correlative) . '<br>'
                                    . 'El cliente paga solo la diferencia: <strong>S/ ' . number_format(max(0, $total - $credit), 2) . '</strong></div>'
                                );
                            }),
                    ]),

                // ==========================================
                // PRECIO MAYORISTA PARA 1 PRENDA (cliente mayorista, con aprobación)
                // ==========================================
                Forms\Components\Section::make('Precio mayorista con aprobación')
                    ->description('Para un cliente mayorista que lleva menos prendas que el mínimo. Lo aprueba un administrador con su PIN.')
                    ->icon('heroicon-o-shield-check')
                    ->collapsible()
                    ->visible(function (Get $get, string $operation) {
                        if ($operation !== 'create' || $get('is_quick_sale')) {
                            return false;
                        }
                        $customer = $get('customer_id') ? \App\Models\Customer::find($get('customer_id')) : null;

                        return $customer?->customer_type === 'mayorista';
                    })
                    ->schema([
                        Forms\Components\Hidden::make('wholesale_request_id'),
                        Forms\Components\Placeholder::make('wholesale_request_status')
                            ->label('Estado')
                            ->content(function (Get $get) {
                                $request = $get('wholesale_request_id') ? \App\Models\WholesalePriceRequest::with('decider')->find($get('wholesale_request_id')) : null;
                                if (!$request) {
                                    return 'Sin solicitud. Con ' . \App\Models\CompanySetting::wholesaleMinQty() . ' prendas o más el precio mayorista se aplica solo.';
                                }

                                return match ($request->status) {
                                    'pendiente' => '⏳ Pendiente: se avisó a los administradores. Pulsa "Ver si ya aprobaron".',
                                    'aprobada' => '✅ Aprobada por ' . ($request->decider?->name ?? 'administrador') . '.',
                                    'rechazada' => '❌ Rechazada. ' . ($request->note ?? ''),
                                    default => ucfirst($request->status),
                                };
                            }),
                        Forms\Components\Actions::make([
                            Forms\Components\Actions\Action::make('request_wholesale')
                                ->label('Pedir aprobación al administrador')
                                ->icon('heroicon-o-bell-alert')
                                ->color('warning')
                                ->visible(fn (Get $get) => blank($get('wholesale_request_id')))
                                ->form([
                                    Forms\Components\TextInput::make('note')->label('Motivo (opcional)')->maxLength(200),
                                ])
                                ->action(function (array $data, Get $get, Set $set) {
                                    try {
                                        $request = \App\Services\WholesaleApprovalService::request(
                                            $get('customer_id'), $get('branch_id'), $get('items') ?? [], Auth::user(), $data['note'] ?? null,
                                        );
                                    } catch (\Illuminate\Validation\ValidationException $e) {
                                        Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();
                                        return;
                                    }
                                    $set('wholesale_request_id', $request->id);
                                    Notification::make()->title('Solicitud enviada')->body('Los administradores recibieron el aviso.')->success()->send();
                                }),

                            Forms\Components\Actions\Action::make('check_wholesale')
                                ->label('Ver si ya aprobaron')
                                ->icon('heroicon-o-arrow-path')
                                ->color('gray')
                                ->visible(fn (Get $get) => filled($get('wholesale_request_id')))
                                ->action(function (Get $get, Set $set) {
                                    $request = \App\Models\WholesalePriceRequest::find($get('wholesale_request_id'));
                                    if ($request?->status === 'rechazada') {
                                        $set('wholesale_request_id', null);
                                        Notification::make()->title('La solicitud fue rechazada')->body($request->note)->danger()->send();
                                    }
                                    \App\Support\SaleCart::recalc($get, $set);
                                }),

                            Forms\Components\Actions\Action::make('approve_on_spot')
                                ->label('Aprobar aquí con PIN')
                                ->icon('heroicon-o-key')
                                ->color('success')
                                ->form([
                                    Forms\Components\Select::make('approver_id')
                                        ->label('Administrador')
                                        ->options(fn () => \App\Services\WholesaleApprovalService::approvers()->pluck('name', 'id'))
                                        ->default(fn () => Auth::user()?->can('authorize_wholesale_price') ? Auth::id() : null)
                                        ->required(),
                                    Forms\Components\TextInput::make('pin')
                                        ->label('PIN del administrador')
                                        ->password()
                                        ->required(),
                                ])
                                ->action(function (array $data, Get $get, Set $set) {
                                    try {
                                        $request = \App\Services\WholesaleApprovalService::approveOnSpot(
                                            $get('customer_id'), $get('branch_id'), $get('items') ?? [], Auth::user(),
                                            \App\Models\User::findOrFail($data['approver_id']), $data['pin'],
                                        );
                                    } catch (\Illuminate\Validation\ValidationException $e) {
                                        Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();
                                        return;
                                    }
                                    $set('wholesale_request_id', $request->id);
                                    \App\Support\SaleCart::recalc($get, $set);
                                    Notification::make()->title('Precio mayorista aprobado')->success()->send();
                                }),
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
                                // Venta a crédito: solo clientes mayoristas con crédito habilitado (decisión 8)
                                Forms\Components\ToggleButtons::make('payment_condition')
                                    ->label('Condición de pago')
                                    ->options(['contado' => 'Contado', 'credito' => 'Crédito'])
                                    ->icons(['contado' => 'heroicon-o-banknotes', 'credito' => 'heroicon-o-calendar-days'])
                                    ->default('contado')
                                    ->inline()
                                    ->live()
                                    ->visible(fn (Get $get, string $operation) => $operation === 'create'
                                        && (bool) \App\Models\Customer::find($get('customer_id'))?->canBuyOnCredit())
                                    ->helperText(function (Get $get) {
                                        $c = \App\Models\Customer::find($get('customer_id'));
                                        if (!$c || $get('payment_condition') !== 'credito') {
                                            return null;
                                        }
                                        $limite = $c->credit_limit !== null ? 'límite S/ ' . number_format((float) $c->credit_limit, 2) : 'sin límite';

                                        return 'Debe S/ ' . number_format($c->outstandingBalance(), 2) . " ({$limite}). Vence en {$c->credit_days} días. "
                                            . 'Abajo registra solo el adelanto (puede ser 0). Usa factura o nota de venta.';
                                    })
                                    ->columnSpan(12),

                                Forms\Components\Repeater::make('payments')
                                    ->label(fn (Get $get) => $get('payment_condition') === 'credito' ? 'Adelanto (opcional)' : 'Desglose de Pagos')
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

                Tables\Columns\TextColumn::make('credit_balance')
                    ->label('Saldo por cobrar')
                    ->money('PEN')
                    ->alignment('right')
                    ->badge()
                    ->color(fn (Sale $record) => \App\Services\CreditService::isOverdue($record) ? 'danger' : 'warning')
                    ->description(fn (Sale $record) => $record->due_date ? ('Vence ' . $record->due_date->format('d/m/Y')) : null)
                    ->visible(fn () => Sale::where('credit_balance', '>', 0)->exists())
                    ->formatStateUsing(fn ($state) => (float) $state > 0 ? 'S/ ' . number_format((float) $state, 2) : '—'),

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
                    ->visible(fn () => auth()->user()?->seesAllBranches() ?? false),

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
                    ->visible(fn () => auth()->user()?->seesAllBranches() ?? false),

                Tables\Filters\SelectFilter::make('warehouse_id')
                    ->relationship('warehouse', 'name', function (\Illuminate\Database\Eloquent\Builder $query) {
                        if (!auth()->user()?->seesAllBranches()) {
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
                    static::abonoAction(Tables\Actions\Action::make('registrar_abono')),

                    Tables\Actions\Action::make('cambio_devolucion')
                        ->label('Cambio / devolución')
                        ->icon('heroicon-o-arrows-right-left')
                        ->color('warning')
                        ->visible(fn (Sale $record) => auth()->user()?->can('exchange_sale')
                            && !in_array($record->sunat_status, ['anulado', 'rechazado'], true)
                            && blank($record->credit_note_serie)
                            && !empty(\App\Services\SaleExchangeService::returnable($record)))
                        ->modalHeading(fn (Sale $record) => "Cambio o devolución · {$record->serie}-{$record->correlative}")
                        ->modalDescription('Las prendas devueltas vuelven al almacén. Si fue boleta o factura se emite una nota de crédito por esas prendas.')
                        ->modalWidth('3xl')
                        ->form(function (Sale $record) {
                            $available = \App\Services\SaleExchangeService::returnable($record);
                            $fields = [];
                            foreach ($record->items()->whereIn('id', array_keys($available))->get() as $item) {
                                $fields[] = Forms\Components\TextInput::make("qty.{$item->id}")
                                    ->label($item->product_name . ' · S/ ' . number_format((float) $item->total / max(1, $item->quantity), 2))
                                    ->helperText("Puede devolver hasta {$available[$item->id]}")
                                    ->numeric()->minValue(0)->maxValue($available[$item->id])->default(0);
                            }

                            return [
                                Forms\Components\Fieldset::make('Prendas que devuelve el cliente')->columns(1)->schema($fields),
                                Forms\Components\Select::make('reason')->label('Motivo')->options(\App\Models\SaleReturn::REASONS)->required(),
                                Forms\Components\ToggleButtons::make('type')
                                    ->label('¿Qué hace el cliente?')
                                    ->options(['cambio' => 'Cambia por otra prenda', 'devolucion' => 'Devolución de dinero (administrador)'])
                                    ->default('cambio')
                                    ->disableOptionWhen(fn (string $value) => $value === 'devolucion' && !auth()->user()?->can('void_sale'))
                                    ->inline()
                                    ->required(),
                            ];
                        })
                        ->action(function (Sale $record, array $data, $livewire) {
                            try {
                                $return = \App\Services\SaleExchangeService::register($record, $data['qty'] ?? [], $data['type'], $data['reason']);
                            } catch (\Illuminate\Validation\ValidationException $e) {
                                Notification::make()->title('No se pudo registrar')->body(collect($e->errors())->flatten()->implode("\n"))->danger()->persistent()->send();

                                return;
                            }

                            $nc = match ($return->sunat_status) {
                                'aceptado' => "Nota de crédito {$return->credit_note_number} aceptada por SUNAT.",
                                'interna' => 'Nota de venta interna: no requiere nota de crédito.',
                                default => 'La nota de crédito quedó pendiente: reintenta desde "Cambios y devoluciones". ' . ($return->sunat_error ?? ''),
                            };

                            if ($return->type === 'devolucion') {
                                try {
                                    \App\Services\SaleExchangeService::refundCash($return, (float) $return->total);
                                    $nc .= ' Se registró la salida de S/ ' . number_format((float) $return->total, 2) . ' de la caja.';
                                } catch (\Illuminate\Validation\ValidationException $e) {
                                    $nc .= ' ' . collect($e->errors())->flatten()->first();
                                }
                                Notification::make()->title('Devolución registrada')->body($nc)
                                    ->{$return->sunat_status === 'pendiente' ? 'warning' : 'success'}()->persistent()->send();

                                return;
                            }

                            Notification::make()->title('Prendas recibidas. Ahora registra la prenda nueva.')
                                ->body('Saldo a favor: S/ ' . number_format((float) $return->total, 2) . '. ' . $nc)
                                ->{$return->sunat_status === 'pendiente' ? 'warning' : 'success'}()->persistent()->send();

                            $livewire->redirect(SaleResource::getUrl('create', ['cambio' => $return->id]));
                        }),

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
                            auth()->user()?->seesAllBranches() &&
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

                    // 'Auditar venta rápida' se retiró junto con la venta rápida (decisión 3).
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
                        ->visible(fn () => auth()->user()?->seesAllBranches())
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

    /** Abono a una venta a crédito (se usa en Ventas y en Cuentas por cobrar). */
    public static function abonoAction($action)
    {
        return $action
            ->label('Registrar abono')
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->visible(fn (Sale $record) => (float) $record->credit_balance > 0 && auth()->user()?->can('create_customer::payment'))
            ->modalHeading(fn (Sale $record) => "Abono a {$record->serie}-{$record->correlative}")
            ->modalDescription(fn (Sale $record) => 'Saldo pendiente: S/ ' . number_format((float) $record->credit_balance, 2)
                . ($record->due_date ? ' · vence ' . $record->due_date->format('d/m/Y') : ''))
            ->form(fn (Sale $record) => [
                Forms\Components\TextInput::make('amount')->label('Monto')->numeric()->prefix('S/')
                    ->minValue(0.01)->maxValue((float) $record->credit_balance)->default((float) $record->credit_balance)->required(),
                Forms\Components\Select::make('payment_method')->label('Medio de pago')
                    ->options(\App\Services\CreditService::METHODS)->default('efectivo')->required(),
                Forms\Components\TextInput::make('reference')->label('N.º de operación (opcional)')->maxLength(60),
            ])
            ->action(function (Sale $record, array $data) {
                try {
                    \App\Services\CreditService::registerPayment($record, $data['payment_method'], (float) $data['amount'], $data['reference'] ?? null);
                } catch (\Illuminate\Validation\ValidationException $e) {
                    Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

                    return;
                }
                $saldo = (float) $record->fresh()->credit_balance;
                Notification::make()->title('Abono registrado')
                    ->body($saldo > 0 ? 'Saldo pendiente: S/ ' . number_format($saldo, 2) : 'La venta quedó pagada.')
                    ->success()->send();
            });
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = Auth::user();

        if ($user && !$user->seesAllBranches()) {
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
