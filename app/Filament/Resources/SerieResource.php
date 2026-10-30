<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SerieResource\Pages;
use App\Models\Serie;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SerieResource extends Resource
{
    protected static ?string $model = Serie::class;

    protected static ?string $modelLabel = 'Serie y Correlativo';
    protected static ?string $pluralModelLabel = 'Series de Comprobantes';
    protected static ?string $navigationIcon = 'heroicon-o-ticket';
    protected static ?string $navigationGroup = '4. Configuración';
    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('document_type')
                    ->label('Tipo de Comprobante')
                    ->options([
                        '01' => 'Factura',
                        '03' => 'Boleta',
                        '00' => 'Nota de Venta',
                        '07' => 'Nota de Crédito',
                        '08' => 'Nota de Débito',
                        '09' => 'Guía de Remisión',
                    ])
                    ->live()
                    ->required(),

                Forms\Components\TextInput::make('serie')
                    ->label('Serie (Ej: B001, F001, NV01, FC01, BC01, T001)')
                    ->required()
                    ->length(4) // 🌟 Regla 1: Obliga a que sean exactamente 4 caracteres
                    ->unique('series', 'serie', ignoreRecord: true) // 🌟 Regla 2: Prohíbe crear series repetidas
                    ->validationMessages([
                        'unique' => 'Esta serie ya existe. Cada serie debe ser única en toda la empresa.',
                    ])
                    ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                    ->dehydrateStateUsing(fn($state) => strtoupper($state))
                    ->rules(fn (Forms\Get $get): array => [
                        function (string $attribute, $value, \Closure $fail) use ($get) {
                            if (!$value) return;
                            $tipo = $get('document_type');
                            $val = strtoupper($value);

                            if ($tipo === '00' && !str_starts_with($val, 'NV')) {
                                $fail('⚠️ Las Notas de Venta internas deben empezar obligatoriamente con "NV" (Ej: NV01).');
                            }
                            if ($tipo === '01' && !str_starts_with($val, 'F')) {
                                $fail('⚠️ ERROR SUNAT: Las series de Factura deben empezar obligatoriamente con la letra "F" (Ej: F001).');
                            }
                            if ($tipo === '03' && !str_starts_with($val, 'B')) {
                                $fail('⚠️ ERROR SUNAT: Las series de Boleta deben empezar obligatoriamente con la letra "B" (Ej: B001).');
                            }
                            if (in_array($tipo, ['07', '08']) && !str_starts_with($val, 'F') && !str_starts_with($val, 'B')) {
                                $fail('⚠️ ERROR SUNAT: Las series de Notas de Crédito (07) y Débito (08) deben empezar obligatoriamente con "F" o "B" (Ej: FC01, BC01, FD01, BD01).');
                            }
                            if ($tipo === '09' && !str_starts_with($val, 'T')) {
                                $fail('⚠️ ERROR SUNAT: Las series de Guía de Remisión deben empezar obligatoriamente con la letra "T" (Ej: T001).');
                            }
                        }
                    ]),

                Forms\Components\TextInput::make('current_number')
                    ->label('Siguiente número a emitir') // 🌟 Etiqueta corregida para que no haya confusión
                    ->numeric()
                    ->minValue(1)
                    ->default(1)
                    ->required()
                    ->helperText('Ejemplo: Si pones 1, la próxima venta que hagas será la número 1. Si pones 150, la próxima será la 150.'),

                Forms\Components\Select::make('branch_id')
                    ->label('Sucursal a la que pertenece')
                    ->relationship('branch', 'name')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->default(fn () => auth()->user()?->branch_id ?? 1)
                    ->disabled(fn () => !auth()->user()?->hasRole('super_admin'))
                    ->dehydrated(),

                Forms\Components\Toggle::make('is_active')
                    ->label('Serie Activa')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('branch.name') // 🌟 Para ver la sucursal en la tabla
                    ->label('Sucursal')
                    ->badge()
                    ->color('info')
                    ->sortable(),

                Tables\Columns\TextColumn::make('document_type')
                    ->label('Tipo')
                    ->formatStateUsing(fn(string $state): string => match ($state) {
                        '01' => 'Factura',
                        '03' => 'Boleta',
                        '00' => 'Nota de Venta',
                        '07' => 'Nota de Crédito',
                        '08' => 'Nota de Débito',
                        '09' => 'Guía de Remisión',
                        default => $state,
                    })
                    ->badge(),

                Tables\Columns\TextColumn::make('serie')
                    ->label('Serie')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('current_number')
                    ->label('Siguiente número a emitir')
                    ->numeric(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Activo')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('branch_id')
                    ->relationship('branch', 'name')
                    ->label('Sucursal')
                    ->searchable()
                    ->preload()
                    ->visible(fn () => auth()->user()?->hasRole('super_admin') ?? false),

                Tables\Filters\SelectFilter::make('document_type')
                    ->label('Tipo de Comprobante')
                    ->options([
                        '01' => 'Factura',
                        '03' => 'Boleta',
                        '00' => 'Nota de Venta',
                        '07' => 'Nota de Crédito',
                        '08' => 'Nota de Débito',
                        '09' => 'Guía de Remisión',
                    ]),
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

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $query = parent::getEloquentQuery()->with('branch');

        if (!auth()->user()?->hasRole('super_admin')) {
            $userBranchId = auth()->user()?->branch_id ?? 1;
            $query->where('branch_id', $userBranchId);
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
            'index' => Pages\ListSeries::route('/'),
            'create' => Pages\CreateSerie::route('/create'),
            'edit' => Pages\EditSerie::route('/{record}/edit'),
        ];
    }
}
