<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WarehouseResource\Pages;
use App\Models\Warehouse;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class WarehouseResource extends Resource
{
    protected static ?string $model = Warehouse::class;

    protected static ?string $navigationIcon = 'heroicon-o-home-modern';
    protected static ?string $navigationGroup = '3. Administración';
    protected static ?int $navigationSort = 3;
    protected static ?string $modelLabel = 'Almacén';
    protected static ?string $pluralModelLabel = 'Almacenes';

    public static function form(Form $form): Form
    {
        $isSuperAdmin = auth()->user()?->hasRole('super_admin') ?? false;

        return $form
            ->schema([
                Forms\Components\Section::make('Información del Almacén')
                    ->schema([
                        Forms\Components\Select::make('branch_id')
                            ->relationship('branch', 'name')
                            ->label('Sucursal / Tienda')
                            ->required()
                            ->default(fn () => auth()->user()?->branch_id ?? 1)
                            ->visible($isSuperAdmin)
                            ->searchable()
                            ->preload(),

                        Forms\Components\TextInput::make('name')
                            ->label('Nombre del Almacén')
                            ->placeholder('Ej: Almacén Principal, Depósito 2, Mostrador')
                            ->required()
                            ->maxLength(100),

                        Forms\Components\Toggle::make('is_default')
                            ->label('¿Almacén por defecto de la tienda?')
                            ->helperText('Las ventas de esta tienda descontarán stock de este almacén.')
                            ->default(false),

                        Forms\Components\Toggle::make('is_active')
                            ->label('¿Activo?')
                            ->default(true),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre del Almacén')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Sucursal')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\IconColumn::make('is_default')
                    ->label('Por Defecto')
                    ->boolean(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Estado')
                    ->boolean(),

                Tables\Columns\TextColumn::make('inventories_count')
                    ->counts('inventories')
                    ->label('Variantes en Catálogo')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha de Creación')
                    ->dateTime('d/m/Y H:i')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('branch_id')
                    ->relationship('branch', 'name')
                    ->label('Sucursal')
                    ->visible(fn () => auth()->user()?->hasRole('super_admin') ?? false),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Estado')
                    ->boolean(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                // En las pantallas, almacenes se desactivan, no se eliminan masivamente
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('branch');

        // Los usuarios no super_admin solo ven los almacenes de su tienda asignada
        if (!auth()->user()?->hasRole('super_admin')) {
            $userBranchId = auth()->user()?->branch_id ?? 1;
            $query->where('branch_id', $userBranchId);
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
            'index'  => Pages\ListWarehouses::route('/'),
            'create' => Pages\CreateWarehouse::route('/create'),
            'edit'   => Pages\EditWarehouse::route('/{record}/edit'),
        ];
    }
}
