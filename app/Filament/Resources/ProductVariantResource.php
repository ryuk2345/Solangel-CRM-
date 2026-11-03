<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductVariantResource\Pages;
use App\Models\Inventory;
use App\Models\ProductVariant;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lista global de variantes para buscar por SKU o código de barras.
 * Las variantes se crean desde el modelo (generador color × talla).
 */
class ProductVariantResource extends Resource
{
    protected static ?string $model = ProductVariant::class;
    protected static ?string $navigationIcon = 'heroicon-o-qr-code';
    protected static ?string $navigationGroup = '2. Catálogo e Inventario';
    protected static ?int $navigationSort = 2;
    protected static ?string $modelLabel = 'Variante';
    protected static ?string $pluralModelLabel = 'Variantes (SKU)';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Placeholder::make('display')->label('Prenda')
                ->content(fn (?ProductVariant $record) => $record?->display_name),
            Forms\Components\Placeholder::make('sku_view')->label('SKU')->content(fn (?ProductVariant $record) => $record?->sku),
            Forms\Components\TextInput::make('sale_price')->label('Precio minorista propio (S/)')->numeric()->minValue(0.01)->helperText('Vacío = usa el del modelo.'),
            Forms\Components\TextInput::make('wholesale_price')->label('Precio mayorista propio (S/)')->numeric()->minValue(0.01)->helperText('Vacío = usa el del modelo.'),
            Forms\Components\TextInput::make('purchase_price')->label('Costo propio (S/)')->numeric()->minValue(0)
                ->visible(fn () => ProductResource::canSeeCosts()),
            Forms\Components\TextInput::make('barcode')->label('Código de barras')->unique(ignoreRecord: true)->maxLength(13),
            Forms\Components\Toggle::make('is_active')->label('Activa (se puede vender)'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ColorColumn::make('color.hex')->label(''),
                Tables\Columns\TextColumn::make('product.name')->label('Modelo')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('color_name')->label('Color')->searchable(),
                Tables\Columns\TextColumn::make('size')->label('Talla')->badge(),
                Tables\Columns\TextColumn::make('sku')->label('SKU')->searchable()->copyable()->fontFamily('mono'),
                Tables\Columns\TextColumn::make('barcode')->label('Código de barras')->searchable()->copyable()->fontFamily('mono'),
                Tables\Columns\TextColumn::make('retail_price')->label('Minorista')->money('PEN'),
                Tables\Columns\TextColumn::make('effective_wholesale_price')->label('Mayorista')->money('PEN'),
                Tables\Columns\TextColumn::make('stock_total')->label('Stock')
                    ->state(fn (ProductVariant $r) => (int) Inventory::where('product_variant_id', $r->id)->sum('stock')),
                Tables\Columns\IconColumn::make('is_active')->label('Activa')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('color_id')->relationship('color', 'name')->label('Color')->preload(),
                Tables\Filters\SelectFilter::make('size_id')->relationship('sizeOption', 'name')->label('Talla')->preload(),
                Tables\Filters\TernaryFilter::make('is_active')->label('Activa'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('model')->label('Ver modelo')->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (ProductVariant $r) => ProductResource::getUrl('edit', ['record' => $r->product_id])),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['product', 'color'])
            ->whereHas('product', fn ($q) => $q->where('is_service', false));
        $user = auth()->user();
        if ($user && !$user->seesAllBranches()) {
            $query->whereHas('product.branches', fn ($q) => $q->where('branches.id', $user->branch_id ?? 1));
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProductVariants::route('/'),
            'edit' => Pages\EditProductVariant::route('/{record}/edit'),
        ];
    }
}
