<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PurchaseResource\Pages;
use App\Models\Color;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\SizeCurve;
use App\Models\Warehouse;
use App\Services\PurchaseService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Compras a proveedores (Etapa 6). Se registran prenda por prenda o "por curva":
 * paquetes por color × unidades por talla. Al guardar, el stock entra por el kardex.
 */
class PurchaseResource extends Resource
{
    protected static ?string $model = Purchase::class;
    protected static ?string $navigationGroup = '2. Catálogo e Inventario';
    protected static ?string $navigationIcon = 'heroicon-o-truck';
    protected static ?string $navigationLabel = 'Compras';
    protected static ?string $modelLabel = 'Compra';
    protected static ?string $pluralModelLabel = 'Compras';
    protected static ?int $navigationSort = 6;

    public static function variantLabel(?ProductVariant $v): string
    {
        return $v ? "[{$v->sku}] {$v->display_name}" : '';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Datos de la compra')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('supplier_id')
                        ->label('Proveedor')
                        ->relationship('supplier', 'name', fn (Builder $query) => $query->where('is_active', true))
                        ->searchable()->preload()->required()
                        ->createOptionForm(SupplierResource::fields()),
                    Forms\Components\Select::make('warehouse_id')
                        ->label('Almacén que recibe')
                        ->options(fn () => Warehouse::query()
                            ->when(!auth()->user()?->seesAllBranches(), fn ($q) => $q->where('branch_id', auth()->user()?->branch_id ?? 1))
                            ->where('is_active', true)->with('branch')->get()
                            ->mapWithKeys(fn ($w) => [$w->id => ($w->branch?->name ? $w->branch->name . ' · ' : '') . $w->name]))
                        ->default(fn () => Warehouse::where('branch_id', auth()->user()?->branch_id ?? 1)->where('is_default', true)->value('id'))
                        ->required(),
                    Forms\Components\DatePicker::make('issue_date')->label('Fecha')->default(now())->required(),
                    Forms\Components\Select::make('document_type')->label('Comprobante')
                        ->options(Purchase::DOCUMENT_TYPES)->default('factura')->required(),
                    Forms\Components\TextInput::make('document_number')->label('Número')->placeholder('F001-000123')->maxLength(30),
                    Forms\Components\Textarea::make('notes')->label('Observaciones')->rows(1),
                ]),

            Forms\Components\Section::make('Prendas')
                ->description('Agrega por curva (paquetes por color) o prenda por prenda. Las combinaciones color × talla que no existan se crean solas.')
                ->headerActions([
                    Forms\Components\Actions\Action::make('por_curva')
                        ->label('Agregar por curva')
                        ->icon('heroicon-o-squares-plus')
                        ->modalWidth('3xl')
                        ->form([
                            Forms\Components\Select::make('product_id')
                                ->label('Modelo')
                                ->searchable()
                                ->getSearchResultsUsing(fn (string $s) => Product::where('is_service', false)
                                    ->where(fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"))
                                    ->limit(50)->get()->mapWithKeys(fn ($p) => [$p->id => trim("[{$p->code}] {$p->name}")]))
                                ->getOptionLabelUsing(fn ($v) => ($p = Product::find($v)) ? trim("[{$p->code}] {$p->name}") : null)
                                ->required()
                                ->live()
                                ->afterStateUpdated(fn ($state, Set $set) => $set('unit_cost', Product::find($state)?->purchase_price)),
                            Forms\Components\Select::make('size_curve_id')
                                ->label('Curva de tallas')
                                ->options(fn () => SizeCurve::with('sizes')->get()->mapWithKeys(fn ($c) => [$c->id => "{$c->name} ({$c->sizes_label})"]))
                                ->required()
                                ->live(),
                            Forms\Components\Fieldset::make('Unidades por paquete (por talla)')
                                ->visible(fn (Get $get) => filled($get('size_curve_id')))
                                ->columns(6)
                                ->schema(fn (Get $get) => SizeCurve::find($get('size_curve_id'))?->sizes
                                    ->map(fn ($size) => Forms\Components\TextInput::make("units.{$size->id}")
                                        ->label($size->name)->numeric()->minValue(0)->default(1))
                                    ->all() ?? []),
                            Forms\Components\Fieldset::make('Paquetes por color')
                                ->columns(4)
                                ->schema(fn () => Color::ordered()->where('is_active', true)->get()
                                    ->map(fn ($color) => Forms\Components\TextInput::make("packs.{$color->id}")
                                        ->label($color->name)->numeric()->minValue(0)->default(0))
                                    ->all()),
                            Forms\Components\TextInput::make('unit_cost')->label('Costo por unidad (S/)')->numeric()->minValue(0)->prefix('S/'),
                        ])
                        ->action(function (array $data, Get $get, Set $set) {
                            try {
                                $lines = PurchaseService::expandCurve(
                                    Product::findOrFail($data['product_id']),
                                    $data['packs'] ?? [],
                                    $data['units'] ?? [],
                                    (float) ($data['unit_cost'] ?? 0),
                                );
                            } catch (ValidationException $e) {
                                Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();

                                return;
                            }

                            $items = collect($get('lines') ?? [])->filter(fn ($l) => !empty($l['product_variant_id']))->all();
                            foreach ($lines as $line) {
                                $items[(string) \Illuminate\Support\Str::uuid()] = $line;
                            }
                            $set('lines', $items);

                            Notification::make()
                                ->title('Agregadas ' . collect($lines)->sum('quantity') . ' prendas en ' . count($lines) . ' tallas y colores')
                                ->success()->send();
                        }),
                ])
                ->schema([
                    Forms\Components\Repeater::make('lines')
                        ->hiddenLabel()
                        ->addActionLabel('Agregar prenda')
                        ->defaultItems(0)
                        ->columns(12)
                        ->live()
                        ->schema([
                            Forms\Components\Select::make('product_variant_id')
                                ->label('Prenda')
                                ->searchable()
                                ->getSearchResultsUsing(fn (string $s) => ProductVariant::with('product')
                                    ->whereHas('product', fn ($p) => $p->where('is_service', false))
                                    ->where(fn ($q) => $q->where('barcode', $s)->orWhere('sku', 'like', "%{$s}%")
                                        ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$s}%")))
                                    ->limit(50)->get()->mapWithKeys(fn ($v) => [$v->id => static::variantLabel($v)]))
                                ->getOptionLabelUsing(fn ($v) => static::variantLabel(ProductVariant::with('product')->find($v)))
                                ->required()
                                ->live()
                                ->afterStateUpdated(fn ($state, Set $set) => $set('unit_cost', ProductVariant::with('product')->find($state)?->cost_price))
                                ->columnSpan(7),
                            Forms\Components\TextInput::make('quantity')->label('Cantidad')->numeric()->minValue(1)->default(1)->required()->live(onBlur: true)->columnSpan(2),
                            Forms\Components\TextInput::make('unit_cost')->label('Costo unit.')->numeric()->minValue(0)->prefix('S/')->live(onBlur: true)->columnSpan(3),
                        ]),
                    Forms\Components\Placeholder::make('resumen')
                        ->hiddenLabel()
                        ->content(function (Get $get) {
                            $lines = collect($get('lines') ?? []);
                            $units = $lines->sum(fn ($l) => (int) ($l['quantity'] ?? 0));
                            $total = $lines->sum(fn ($l) => (int) ($l['quantity'] ?? 0) * (float) ($l['unit_cost'] ?? 0));

                            return "Total: {$units} prendas · S/ " . number_format($total, 2);
                        }),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('N.º')->sortable(),
                Tables\Columns\TextColumn::make('issue_date')->label('Fecha')->date('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('supplier.name')->label('Proveedor')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('document_number')->label('Comprobante')
                    ->formatStateUsing(fn ($state, Purchase $r) => (Purchase::DOCUMENT_TYPES[$r->document_type] ?? '') . ($state ? " {$state}" : ''))
                    ->searchable(),
                Tables\Columns\TextColumn::make('warehouse.name')->label('Almacén'),
                Tables\Columns\TextColumn::make('items_sum_quantity')->sum('items', 'quantity')->label('Prendas'),
                Tables\Columns\TextColumn::make('total')->label('Total')->money('PEN')
                    ->visible(fn () => auth()->user()?->can('view_cost_price')),
                Tables\Columns\TextColumn::make('status')->label('Estado')->badge()
                    ->formatStateUsing(fn ($s) => ucfirst($s))
                    ->color(fn ($s) => $s === 'anulada' ? 'danger' : 'success'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('supplier_id')->relationship('supplier', 'name')->label('Proveedor')->preload(),
                Tables\Filters\SelectFilter::make('status')->label('Estado')->options(['registrada' => 'Registrada', 'anulada' => 'Anulada']),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                static::labelsAction(Tables\Actions\Action::make('etiquetas')),
                static::voidAction(Tables\Actions\Action::make('anular')),
            ])
            ->bulkActions([]);
    }

    public static function labelsAction($action)
    {
        return $action
            ->label('Etiquetas')
            ->icon('heroicon-o-qr-code')
            ->color('gray')
            ->visible(fn (Purchase $record) => $record->status === 'registrada' && auth()->user()?->can('print_labels'))
            ->url(fn (Purchase $record) => route('labels.purchase', $record))
            ->openUrlInNewTab();
    }

    public static function voidAction($action)
    {
        return $action
            ->label('Anular')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Purchase $record) => $record->status === 'registrada' && auth()->user()?->can('update_purchase'))
            ->requiresConfirmation()
            ->modalDescription('Se sacan del almacén las prendas de esta compra. Si alguna ya se vendió o se movió, no se podrá anular.')
            ->form([Forms\Components\TextInput::make('reason')->label('Motivo')->required()->maxLength(150)])
            ->action(function (Purchase $record, array $data) {
                try {
                    PurchaseService::void($record, $data['reason']);
                    Notification::make()->title('Compra anulada')->success()->send();
                } catch (ValidationException $e) {
                    Notification::make()->title('No se pudo anular')->body(collect($e->errors())->flatten()->implode("\n"))->danger()->persistent()->send();
                }
            });
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make()->columns(4)->schema([
                Infolists\Components\TextEntry::make('supplier.name')->label('Proveedor'),
                Infolists\Components\TextEntry::make('issue_date')->label('Fecha')->date('d/m/Y'),
                Infolists\Components\TextEntry::make('document_number')->label('Comprobante')
                    ->formatStateUsing(fn ($state, Purchase $r) => (Purchase::DOCUMENT_TYPES[$r->document_type] ?? '') . ($state ? " {$state}" : ''))
                    ->placeholder('—'),
                Infolists\Components\TextEntry::make('warehouse.name')->label('Almacén'),
                Infolists\Components\TextEntry::make('status')->label('Estado')->badge(),
                Infolists\Components\TextEntry::make('user.name')->label('Registró'),
                Infolists\Components\TextEntry::make('total')->label('Total')->money('PEN')
                    ->visible(fn () => auth()->user()?->can('view_cost_price')),
                Infolists\Components\TextEntry::make('notes')->label('Observaciones')->placeholder('—'),
            ]),
            Infolists\Components\RepeatableEntry::make('items')->label('Prendas')->columns(4)->schema([
                Infolists\Components\TextEntry::make('productVariant.display_name')->label('Prenda'),
                Infolists\Components\TextEntry::make('productVariant.sku')->label('SKU'),
                Infolists\Components\TextEntry::make('quantity')->label('Cantidad'),
                Infolists\Components\TextEntry::make('unit_cost')->label('Costo unit.')->money('PEN')
                    ->visible(fn () => auth()->user()?->can('view_cost_price')),
            ]),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['supplier', 'warehouse']);
        if (!auth()->user()?->seesAllBranches()) {
            $query->where('branch_id', auth()->user()?->branch_id ?? 1);
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchases::route('/'),
            'create' => Pages\CreatePurchase::route('/create'),
            'view' => Pages\ViewPurchase::route('/{record}'),
        ];
    }
}
