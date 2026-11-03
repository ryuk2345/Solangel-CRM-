<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InventoryResource\Pages;
use App\Models\Inventory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InventoryResource extends Resource
{
    protected static ?string $model = Inventory::class;

    protected static ?string $navigationIcon = 'heroicon-o-archive-box';
    protected static ?string $navigationGroup = '2. Catálogo e Inventario';
    protected static ?int $navigationSort = 2;
    protected static ?string $modelLabel = 'Inventario';

    public static function form(Form $form): Form
    {
        $isSuperAdmin = auth()->user()?->hasRole('super_admin') ?? false;

        return $form
            ->schema([
                Forms\Components\Select::make('branch_id')
                    ->relationship('branch', 'name')
                    ->label('Sucursal / Tienda')
                    ->required()
                    ->default(fn () => auth()->user()?->branch_id ?? 1)
                    ->visible($isSuperAdmin)
                    ->live()
                    ->afterStateUpdated(fn (Set $set) => $set('warehouse_id', null))
                    ->preload(),

                Forms\Components\Select::make('warehouse_id')
                    ->label('Almacén')
                    ->options(function (Get $get) {
                        $branchId = $get('branch_id') ?? (auth()->user()?->branch_id ?? 1);
                        return \App\Models\Warehouse::where('branch_id', $branchId)
                            ->where('is_active', true)
                            ->pluck('name', 'id');
                    })
                    ->required()
                    ->default(function (Get $get) {
                        $branchId = $get('branch_id') ?? (auth()->user()?->branch_id ?? 1);
                        return \App\Models\Warehouse::where('branch_id', $branchId)
                            ->where('is_default', true)
                            ->value('id') ?? \App\Models\Warehouse::where('branch_id', $branchId)->value('id');
                    })
                    ->searchable()
                    ->preload(),

                Forms\Components\Select::make('product_variant_id')
                    ->options(function (Get $get) {
                        $branchId = $get('branch_id') ?? (auth()->user()?->branch_id ?? 1);
                        return \App\Models\ProductVariant::with('product')
                            ->whereHas('product.branches', fn($q) => $q->where('branches.id', $branchId))
                            ->get()
                            ->mapWithKeys(function ($variant) {
                                $brandInfo = $variant->color_name ? ' - Marca: ' . $variant->color_name : '';
                                return [$variant->id => ($variant->product?->name ?? 'Producto') . ' - Modelo: ' . $variant->size . $brandInfo];
                            });
                    })
                    ->label('Prenda (modelo / color / talla)')
                    ->required()
                    ->searchable()
                    ->unique(
                        modifyRuleUsing: function (\Illuminate\Validation\Rules\Unique $rule, Get $get) {
                            return $rule->where('warehouse_id', $get('warehouse_id'));
                        },
                        ignoreRecord: true
                    )
                    ->validationMessages([
                        'unique' => '⚠️ Esta prenda ya está registrada en este almacén.',
                    ]),

                Forms\Components\TextInput::make('stock')
                    ->label('Cantidad Disponible (Stock Físico)')
                    ->required()
                    ->numeric()
                    ->default(0)
                    ->minValue(0),
            ]);
    }

    public static function table(Table $table): Table
    {
        $isSuperAdmin = auth()->user()?->hasRole('super_admin') ?? false;

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Sucursal')
                    ->sortable()
                    ->searchable()
                    ->visible($isSuperAdmin),

                Tables\Columns\TextColumn::make('warehouse.name')
                    ->label('Almacén')
                    ->sortable()
                    ->searchable()
                    ->weight('semibold'),

                Tables\Columns\TextColumn::make('productVariant.product.name')
                    ->label('Producto')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('productVariant.sku')
                    ->label('SKU')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('productVariant.size')
                    ->label('Modelo')
                    ->searchable(),

                Tables\Columns\TextColumn::make('productVariant.color_name')
                    ->label('Marca')
                    ->searchable(),

                Tables\Columns\TextColumn::make('stock')
                    ->label('Stock Actual')
                    ->numeric()
                    ->sortable()
                    ->badge()
                    ->color(fn(string $state): string => match (true) {
                        (int) $state <= 5 => 'danger',
                        default => 'success',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('branch_id')
                    ->relationship('branch', 'name')
                    ->label('Sucursal')
                    ->searchable()
                    ->preload()
                    ->visible($isSuperAdmin),

                Tables\Filters\SelectFilter::make('warehouse_id')
                    ->relationship('warehouse', 'name', function (Builder $query) {
                        if (!auth()->user()?->hasRole('super_admin')) {
                            $query->where('branch_id', auth()->user()?->branch_id ?? 1);
                        }
                        return $query->where('is_active', true);
                    })
                    ->label('Almacén')
                    ->searchable()
                    ->preload(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                // En multisucursal no se permite borrado masivo de inventario
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->with(['branch', 'warehouse', 'productVariant.product'])
            ->whereHas('productVariant.product', function ($q) {
                $q->where('is_service', false);
            });

        // Los usuarios que no son super_admin solo ven el inventario de su propia tienda
        if (!auth()->user()?->hasRole('super_admin')) {
            $userBranchId = auth()->user()?->branch_id ?? 1;
            $query->where('branch_id', $userBranchId)
                  ->whereHas('productVariant.product.branches', fn($q) => $q->where('branches.id', $userBranchId));
        }

        return $query;
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListInventories::route('/'),
            'create' => Pages\CreateInventory::route('/create'),
            'edit'   => Pages\EditInventory::route('/{record}/edit'),
        ];
    }
}