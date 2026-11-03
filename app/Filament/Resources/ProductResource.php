<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Filament\Resources\ProductResource\RelationManagers\VariantsRelationManager;
use App\Models\Color;
use App\Models\Product;
use App\Models\Size;
use App\Models\SizeCurve;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Modelos de prendas. Cada modelo genera sus variantes (color × talla) con SKU,
 * código de barras y dos precios: minorista y mayorista.
 */
class ProductResource extends Resource
{
    protected static ?string $model = Product::class;
    protected static ?string $navigationGroup = '2. Catálogo e Inventario';
    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';
    protected static ?int $navigationSort = 1;
    protected static ?string $modelLabel = 'Modelo';
    protected static ?string $pluralModelLabel = 'Modelos (productos)';
    protected static ?string $navigationLabel = 'Modelos (productos)';
    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'code', 'variants.sku', 'variants.barcode'];
    }

    public static function canSeeCosts(): bool
    {
        return (bool) auth()->user()?->can('view_cost_price');
    }

    /** Opciones de color con su muestra, para los selectores. */
    public static function colorOptions(): array
    {
        return Color::where('is_active', true)->ordered()->get()
            ->mapWithKeys(fn (Color $c) => [$c->id => "<span style=\"display:inline-block;width:12px;height:12px;border-radius:9999px;border:1px solid #d1d5db;background:{$c->hex};margin-right:6px;vertical-align:middle\"></span>" . e($c->name)])
            ->all();
    }

    /** Campos del generador de variantes (se usan al crear y en "Agregar colores o tallas"). */
    public static function variantGeneratorFields(bool $dehydrated = false): array
    {
        return [
            Forms\Components\Select::make('gen_colors')
                ->label('Colores')
                ->options(fn () => static::colorOptions())
                ->allowHtml()
                ->multiple()
                ->searchable()
                ->required()
                ->dehydrated($dehydrated),
            Forms\Components\Select::make('gen_curve')
                ->label('Curva de tallas')
                ->options(fn () => SizeCurve::orderBy('name')->get()->mapWithKeys(fn ($c) => [$c->id => "{$c->name} ({$c->sizes_label})"]))
                ->live()
                ->afterStateUpdated(fn ($state, Set $set) => $set('gen_sizes', $state ? SizeCurve::find($state)?->sizes->pluck('id')->all() : []))
                ->dehydrated($dehydrated),
            Forms\Components\Select::make('gen_sizes')
                ->label('Tallas')
                ->helperText('Se llenan con la curva; puedes quitar o agregar tallas.')
                ->options(fn () => Size::ordered()->pluck('name', 'id'))
                ->multiple()
                ->searchable()
                ->required()
                ->dehydrated($dehydrated),
            Forms\Components\Placeholder::make('gen_summary')
                ->label('')
                ->content(function (Get $get) {
                    $c = count($get('gen_colors') ?? []);
                    $s = count($get('gen_sizes') ?? []);

                    return $c && $s ? "{$c} colores × {$s} tallas = " . ($c * $s) . ' variantes, cada una con su SKU y código de barras.' : '';
                }),
        ];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Datos del modelo')
                ->schema([
                    Forms\Components\TextInput::make('name')->label('Nombre del modelo')->placeholder('Ej: Polo básico algodón pima')
                        ->required()->maxLength(255)->columnSpan(2),
                    Forms\Components\TextInput::make('code')->label('Código del modelo')
                        ->helperText('Base del SKU. Si lo dejas vacío se genera solo (M00012).')
                        ->unique(ignoreRecord: true)->maxLength(20),
                    Forms\Components\Select::make('category_id')->label('Categoría')
                        ->relationship('category', 'name')->searchable()->preload()
                        ->createOptionForm([Forms\Components\TextInput::make('name')->label('Nombre')->required()]),
                    Forms\Components\Select::make('brand_id')->label('Marca')
                        ->relationship('brand', 'name')->searchable()->preload()
                        ->createOptionForm([Forms\Components\TextInput::make('name')->label('Marca')->required()]),
                    Forms\Components\Select::make('season_id')->label('Temporada / colección')
                        ->relationship('season', 'name')->searchable()->preload()
                        ->createOptionForm([Forms\Components\TextInput::make('name')->label('Temporada')->required()]),
                    Forms\Components\TextInput::make('material')->label('Material / composición')->placeholder('Ej: 100% algodón pima'),
                    Forms\Components\TextInput::make('location')->label('Ubicación en almacén')->placeholder('Ej: Estante B-3'),
                    Forms\Components\Select::make('unit_code')->label('Unidad (SUNAT)')
                        ->options(['NIU' => 'Unidad (NIU)', 'PR' => 'Par (PR)', 'SET' => 'Conjunto (SET)', 'DZN' => 'Docena (DZN)'])
                        ->default('NIU')->required(),
                    Forms\Components\Textarea::make('description')->label('Descripción')->rows(2)->columnSpanFull(),
                    Forms\Components\Toggle::make('allows_delivery')->label('Permite delivery')
                        ->helperText('Solo las ventas con al menos un modelo que lo permita pueden agregar delivery.'),
                    Forms\Components\Select::make('branches')->label('Tiendas donde se vende')
                        ->relationship('branches', 'name')->multiple()->preload()
                        ->default(fn () => [auth()->user()?->branch_id ?? 1])
                        ->visible(fn () => auth()->user()?->seesAllBranches())
                        ->columnSpanFull(),
                ])->columns(3),

            Forms\Components\Section::make('Precios')
                ->description('Precios del modelo. Cada talla o color puede tener su propio precio en la lista de variantes.')
                ->schema([
                    Forms\Components\TextInput::make('sale_price')->label('Precio minorista (S/)')
                        ->helperText('1 prenda.')->numeric()->minValue(0.01)->prefix('S/')->required(),
                    Forms\Components\TextInput::make('wholesale_price')->label('Precio mayorista (S/)')
                        ->helperText(fn () => 'Desde ' . \App\Models\CompanySetting::wholesaleMinQty() . ' prendas por venta.')
                        ->numeric()->minValue(0.01)->prefix('S/')->required()
                        ->lte('sale_price')
                        ->validationMessages(['lte' => 'El precio mayorista no puede ser mayor que el minorista.']),
                    Forms\Components\TextInput::make('purchase_price')->label('Costo (S/)')
                        ->numeric()->minValue(0)->prefix('S/')
                        ->visible(fn () => static::canSeeCosts()),
                    Forms\Components\TextInput::make('low_stock_threshold')->label('Stock bajo (por talla y color)')
                        ->helperText(fn () => 'Vacío = usa el general de ' . \App\Models\CompanySetting::lowStockThreshold() . ' unidades.')
                        ->numeric()->minValue(0)->integer(),
                ])->columns(4),

            Forms\Components\Section::make('Colores y tallas')
                ->description('Se crea una variante por cada combinación de color y talla.')
                ->schema(static::variantGeneratorFields())
                ->columns(3)
                ->visibleOn('create'),

            Forms\Components\Section::make('Fotos')
                ->collapsible()
                ->schema([
                    Forms\Components\Repeater::make('images')
                        ->relationship('images')
                        ->label('')
                        ->schema([
                            Forms\Components\FileUpload::make('image_path')->label('Foto')->image()->directory('productos')->disk('public')->required(),
                            Forms\Components\Select::make('color_id')->label('Color de la foto')
                                ->options(fn () => static::colorOptions())->allowHtml()
                                ->helperText('Vacío = foto general del modelo.'),
                            Forms\Components\Toggle::make('is_main')->label('Foto principal'),
                        ])
                        ->columns(3)
                        ->addActionLabel('Agregar foto')
                        ->defaultItems(0),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('images.image_path')->label('Foto')->disk('public')->circular()->stacked()->limit(1),
                Tables\Columns\TextColumn::make('name')->label('Modelo')->searchable()->sortable()
                    ->description(fn (Product $r) => $r->code),
                Tables\Columns\TextColumn::make('category.name')->label('Categoría')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('brand.name')->label('Marca')->toggleable(),
                Tables\Columns\TextColumn::make('season.name')->label('Temporada')->badge()->color('gray')->toggleable(),
                Tables\Columns\TextColumn::make('sale_price')->label('Minorista')->money('PEN')->sortable(),
                Tables\Columns\TextColumn::make('wholesale_price')->label('Mayorista')->money('PEN')->sortable(),
                Tables\Columns\TextColumn::make('variants_count')->label('Variantes')->counts('variants'),
                Tables\Columns\TextColumn::make('stock_total')->label('Stock')
                    ->state(fn (Product $r) => (int) \App\Models\Inventory::whereIn('product_variant_id', $r->variants->pluck('id'))->sum('stock')),
                Tables\Columns\IconColumn::make('allows_delivery')->label('Delivery')->boolean()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('branches.name')->label('Tiendas')->badge()->color('info')
                    ->visible(fn () => auth()->user()?->seesAllBranches())->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category_id')->relationship('category', 'name')->label('Categoría')->preload(),
                Tables\Filters\SelectFilter::make('brand_id')->relationship('brand', 'name')->label('Marca')->preload(),
                Tables\Filters\SelectFilter::make('season_id')->relationship('season', 'name')->label('Temporada')->preload(),
                Tables\Filters\SelectFilter::make('branch_id')->relationship('branches', 'name')->label('Tienda')->preload()
                    ->visible(fn () => auth()->user()?->seesAllBranches()),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->visible(fn (Product $record) => static::userCanManage($record)),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (Product $record) => static::userCanManage($record)),
            ])
            ->bulkActions([]);
    }

    /** Fuera del administrador, solo se edita un modelo que pertenece únicamente a la tienda del usuario. */
    public static function userCanManage(Product $record): bool
    {
        $user = auth()->user();
        if (!$user) {
            return false;
        }
        if ($user->seesAllBranches()) {
            return true;
        }
        $branchIds = $record->branches->pluck('id');

        return $branchIds->count() === 1 && $branchIds->contains($user->branch_id ?? 1);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['variants', 'branches', 'category', 'brand', 'season'])->where('is_service', false);
        $user = auth()->user();
        if ($user && !$user->seesAllBranches()) {
            $branchId = $user->branch_id ?? 1;
            $query->whereHas('branches', fn ($q) => $q->where('branches.id', $branchId));
        }

        return $query;
    }

    public static function getRelations(): array
    {
        return [VariantsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
