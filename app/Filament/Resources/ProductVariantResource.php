<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductVariantResource\Pages;
use App\Filament\Resources\ProductVariantResource\RelationManagers;
use App\Models\ProductVariant;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProductVariantResource extends Resource
{
    protected static ?string $model = ProductVariant::class;

    protected static ?string $navigationIcon = 'heroicon-o-swatch';
    protected static ?string $navigationGroup = '2. Catálogo e Inventario';
    protected static ?int $navigationSort = 4;
    protected static ?string $modelLabel = 'Detalle de Repuesto';
    protected static ?string $pluralModelLabel = 'Detalles de Repuestos';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Detalles del Repuesto')
                    ->schema([
                        Forms\Components\Select::make('product_id')
                            ->relationship('product', 'name')
                            ->label('Producto Principal')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('sku')
                            ->label('SKU / Código Único')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),

                        Forms\Components\TextInput::make('size')
                            ->label('Modelo / Medida')
                            ->helperText('Ej: 2015-2020 o Medida 3/4')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('color_name')
                            ->label('Marca / Fabricante (Ej: Bosch, Denso)')
                            ->maxLength(255),

                        Forms\Components\TextInput::make('color_hex')
                            ->label('Código OEM / Alternativo (Opcional)'),
                    ])->columns(2),

                Forms\Components\Section::make('Precios Específicos (Opcional)')
                    ->description('Llena esto SOLO si esta talla/color tiene un precio diferente al producto principal.')
                    ->schema([
                        Forms\Components\TextInput::make('purchase_price')
                            ->label('Precio de Compra')
                            ->numeric()
                            ->prefix('S/'),

                        Forms\Components\TextInput::make('sale_price')
                            ->label('Precio de Venta')
                            ->numeric()
                            ->prefix('S/'),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('product.name')
                    ->label('Producto')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('sku')
                    ->label('SKU')
                    ->searchable(),

                Tables\Columns\TextColumn::make('size')
                    ->label('Modelo')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('color_name')
                    ->label('Marca')
                    ->searchable(),

                Tables\Columns\TextColumn::make('color_hex')
                    ->label('OEM'),

                Tables\Columns\TextColumn::make('sale_price')
                    ->label('Precio Esp.')
                    ->money('PEN')
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
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
            'index' => Pages\ListProductVariants::route('/'),
            'create' => Pages\CreateProductVariant::route('/create'),
            'edit' => Pages\EditProductVariant::route('/{record}/edit'),
        ];
    }
}
