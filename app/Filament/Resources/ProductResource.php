<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Filament\Resources\ProductResource\RelationManagers;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;
    protected static ?string $navigationGroup = '2. Catálogo e Inventario';
    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';
    protected static ?int $navigationSort = 1;
    protected static ?string $modelLabel = 'Producto';
    protected static ?string $pluralModelLabel = 'Productos';
    protected static ?string $navigationLabel = 'Productos';
    
    protected static ?string $recordTitleAttribute = 'name';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'description', 'category.name'];
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Información Principal')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nombre del Producto')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\Select::make('category_id')
                            ->relationship('category', 'name')
                            ->label('Categoría')
                            ->searchable()
                            ->preload(),

                        // 🌟 Conservamos el Código SUNAT, pero le quitamos "ZZ" porque aquí solo hay productos
                        Forms\Components\Select::make('unit_code')
                            ->label('Código de Unidad (SUNAT)')
                            ->options([
                                'NIU' => 'Unidad / Bienes Físicos (NIU)',
                                'BX'  => 'Caja (BX)',
                                'BO'  => 'Botellas (BO)',
                                'PK'  => 'Paquete (PK)',
                                'BG'  => 'Bolsa (BG)',
                                'PR'  => 'Par (PR)',
                                'KGM' => 'Kilogramos (KGM)',
                                'GRM' => 'Gramos (GRM)',
                                'LTR' => 'Litros (LTR)',
                                'GLL' => 'Galones US (GLL)',
                                'TNE' => 'Toneladas (TNE)',
                                'MTR' => 'Metros (MTR)',
                                'CMT' => 'Centímetros (CMT)',
                                'DZN' => 'Docena (DZN)',
                                'MIL' => 'Millares (MIL)',
                                'RO'  => 'Rollo (RO)',
                                'SET' => 'Conjunto / Set (SET)',
                                'BJ'  => 'Balde (BJ)',
                                'CY'  => 'Cilindro (CY)',
                            ])
                            ->default('NIU')
                            ->searchable(),

                        Forms\Components\Textarea::make('description')
                            ->label('Descripción')
                            ->columnSpanFull(),

                        Forms\Components\Select::make('branches')
                            ->relationship('branches', 'name')
                            ->label('Tiendas Habilitadas')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->default(function () {
                                $user = auth()->user();
                                if ($user && !$user->seesAllBranches()) {
                                    return [$user->branch_id ?? 1];
                                }
                                return [1];
                            })
                            ->visible(fn () => auth()->user()?->seesAllBranches())
                            ->helperText('Selecciona las tiendas donde estará disponible este producto.')
                            ->columnSpanFull(),
                    ])->columns(2),

                Forms\Components\Section::make('Precios y Detalles')
                    ->schema([
                        Forms\Components\TextInput::make('purchase_price')
                            ->label('Precio de Compra (Costo)')
                            ->numeric()
                            ->prefix('S/'),

                        Forms\Components\TextInput::make('sale_price')
                            ->label('Precio de Venta al Público')
                            ->default(0)
                            ->numeric()
                            ->prefix('S/'),

                        Forms\Components\TextInput::make('material')
                            ->label('Material / composición')
                            ->placeholder('Ej: 100% algodón pima')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('location')
                            ->label('Ubicación en Almacén')
                            ->placeholder('Ej: Pasillo A-2'),

                        Forms\Components\TextInput::make('badge')
                            ->label('Etiqueta (Ej: NUEVO, MÁS VENDIDO)')
                            ->maxLength(255),

                        Forms\Components\Toggle::make('is_web_visible')
                            ->label('¿Mostrar en la página web?')
                            ->default(true),
                    ])->columns(2),

                Forms\Components\Section::make('Galería de Imágenes')
                    ->schema([
                        Forms\Components\Repeater::make('images')
                            ->relationship('images')
                            ->label('Fotos del producto')
                            ->schema([
                                Forms\Components\FileUpload::make('image_path')
                                    ->label('Sube la foto')
                                    ->image()
                                    ->directory('productos')
                                    ->disk('public')
                                    ->required(),

                                Forms\Components\Toggle::make('is_main')
                                    ->label('¿Es la foto de portada principal?')
                                    ->default(false),
                            ])
                            ->columns(2)
                            ->addActionLabel('Añadir otra foto')
                            ->defaultItems(1),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('images.image_path')
                    ->label('Foto')
                    ->circular()
                    ->stacked()
                    ->limit(1),
                Tables\Columns\TextColumn::make('name')
                    ->label('Producto')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('category.name')
                    ->label('Categoría')
                    ->sortable(),

                Tables\Columns\TextColumn::make('unit_code')
                    ->label('Unidad')
                    ->badge()
                    ->color('success'),

                Tables\Columns\TextColumn::make('sale_price')
                    ->label('Precio Venta')
                    ->money('PEN')
                    ->sortable(),

                Tables\Columns\TextColumn::make('badge')
                    ->label('Etiqueta')
                    ->badge()
                    ->searchable(),

                Tables\Columns\TextColumn::make('branches.name')
                    ->label('Tiendas')
                    ->badge()
                    ->color('info')
                    ->visible(fn () => auth()->user()?->seesAllBranches()),

                Tables\Columns\IconColumn::make('is_web_visible')
                    ->label('Web')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category_id')
                    ->relationship('category', 'name')
                    ->label('Categoría')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('branch_id')
                    ->relationship('branches', 'name')
                    ->label('Sucursal / Tienda')
                    ->searchable()
                    ->preload()
                    ->visible(fn () => auth()->user()?->seesAllBranches()),

                Tables\Filters\TernaryFilter::make('is_web_visible')
                    ->label('Estado en la Web')
                    ->boolean()
                    ->trueLabel('Solo visibles')
                    ->falseLabel('Solo ocultos')
                    ->placeholder('Todos los productos'),
            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->visible(function (Product $record) {
                        $user = auth()->user();
                        if (!$user) return false;
                        if ($user->seesAllBranches()) return true;
                        $branchIds = $record->branches->pluck('id');
                        return $branchIds->count() === 1 && $branchIds->contains($user->branch_id ?? 1);
                    }),
                Tables\Actions\DeleteAction::make()
                    ->visible(function (Product $record) {
                        $user = auth()->user();
                        if (!$user) return false;
                        if ($user->seesAllBranches()) return true;
                        $branchIds = $record->branches->pluck('id');
                        return $branchIds->count() === 1 && $branchIds->contains($user->branch_id ?? 1);
                    }),
            ])
            ->bulkActions([
                // En catálogo por tienda se prohíbe la eliminación masiva de productos
            ]);
    }

    // 🌟 Catálogo por tienda: si no es super_admin, filtra por la tienda del usuario
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['variants', 'branches'])->where('is_service', false);
        $user = auth()->user();
        if ($user && !$user->seesAllBranches()) {
            $branchId = $user->branch_id ?? 1;
            $query->whereHas('branches', fn($q) => $q->where('branches.id', $branchId));
        }
        return $query;
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
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