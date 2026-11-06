<?php

namespace App\Filament\Resources\ProductResource\RelationManagers;

use App\Filament\Resources\ProductResource;
use App\Models\Inventory;
use App\Models\ProductVariant;
use App\Services\VariantGenerator;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Variantes del modelo (color × talla) con sus precios, código de barras y stock.
 * Los precios vacíos heredan los del modelo.
 */
class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';
    protected static ?string $title = 'Variantes (color × talla)';
    protected static ?string $modelLabel = 'variante';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('sale_price')->label('Precio minorista propio (S/)')->numeric()->minValue(0.01)
                ->helperText('Vacío = usa el del modelo.'),
            Forms\Components\TextInput::make('wholesale_price')->label('Precio mayorista propio (S/)')->numeric()->minValue(0.01)
                ->helperText('Vacío = usa el del modelo.'),
            Forms\Components\TextInput::make('purchase_price')->label('Costo propio (S/)')->numeric()->minValue(0)
                ->visible(fn () => ProductResource::canSeeCosts()),
            Forms\Components\TextInput::make('barcode')->label('Código de barras')
                ->helperText('Se genera solo. Cámbialo solo si la prenda ya trae un código de fábrica.')
                ->unique(ignoreRecord: true)->maxLength(13),
            Forms\Components\Toggle::make('is_active')->label('Activa (se puede vender)'),
        ])->columns(2);
    }

    public function table(Table $table): Table
    {
        $product = $this->getOwnerRecord();

        return $table
            ->modifyQueryUsing(fn (Builder $q) => $q->with(['color', 'sizeOption'])
                ->leftJoin('colors', 'colors.id', '=', 'product_variants.color_id')
                ->leftJoin('sizes', 'sizes.id', '=', 'product_variants.size_id')
                ->select('product_variants.*')
                ->orderBy('colors.sort_order')->orderBy('sizes.sort_order'))
            ->columns([
                Tables\Columns\ColorColumn::make('color.hex')->label(''),
                Tables\Columns\TextColumn::make('color_name')->label('Color'),
                Tables\Columns\TextColumn::make('size')->label('Talla')->badge(),
                Tables\Columns\TextColumn::make('sku')->label('SKU')->searchable()->copyable()->fontFamily('mono'),
                Tables\Columns\TextColumn::make('barcode')->label('Código de barras')->searchable()->copyable()->fontFamily('mono'),
                Tables\Columns\TextColumn::make('retail_price')->label('Minorista')->money('PEN')
                    ->description(fn (ProductVariant $r) => $r->getRawOriginal('sale_price') ? 'propio' : 'del modelo'),
                Tables\Columns\TextColumn::make('effective_wholesale_price')->label('Mayorista')->money('PEN')
                    ->description(fn (ProductVariant $r) => $r->getRawOriginal('wholesale_price') ? 'propio' : 'del modelo'),
                Tables\Columns\TextColumn::make('stock_total')->label('Stock')
                    ->state(fn (ProductVariant $r) => (int) Inventory::where('product_variant_id', $r->id)->sum('stock'))
                    ->badge()
                    ->color(fn ($state) => $state <= 0 ? 'danger' : ($state <= $product->lowStockThreshold() ? 'warning' : 'success')),
                Tables\Columns\IconColumn::make('is_active')->label('Activa')->boolean(),
            ])
            ->headerActions([
                Tables\Actions\Action::make('generate')
                    ->label('Agregar colores o tallas')
                    ->icon('heroicon-o-squares-plus')
                    ->form(ProductResource::variantGeneratorFields(dehydrated: true))
                    ->action(function (array $data) use ($product) {
                        $n = VariantGenerator::generate($product, $data['gen_colors'] ?? [], $data['gen_sizes'] ?? []);
                        Notification::make()->title($n ? "Se crearon {$n} variantes nuevas" : 'Esas combinaciones ya existían')->success()->send();
                    }),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('set_prices')
                    ->label('Cambiar precios')
                    ->icon('heroicon-o-currency-dollar')
                    ->form([
                        Forms\Components\TextInput::make('sale_price')->label('Precio minorista (S/)')->numeric()->minValue(0.01)->helperText('Vacío = no cambiar'),
                        Forms\Components\TextInput::make('wholesale_price')->label('Precio mayorista (S/)')->numeric()->minValue(0.01)->helperText('Vacío = no cambiar'),
                    ])
                    ->action(function ($records, array $data) {
                        $changes = array_filter(['sale_price' => $data['sale_price'] ?? null, 'wholesale_price' => $data['wholesale_price'] ?? null], fn ($v) => filled($v));
                        if ($changes) {
                            $records->each->update($changes);
                        }
                        Notification::make()->title('Precios actualizados')->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
                Tables\Actions\BulkAction::make('inherit_prices')
                    ->label('Usar precios del modelo')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->requiresConfirmation()
                    ->action(fn ($records) => $records->each->update(['sale_price' => null, 'wholesale_price' => null]))
                    ->deselectRecordsAfterCompletion(),
            ]);
    }
}
