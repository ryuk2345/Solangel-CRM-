<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BranchResource\Pages;
use App\Filament\Resources\BranchResource\RelationManagers;
use App\Models\Branch;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Filament\Forms\Set;
use Filament\Forms\Get;

class BranchResource extends Resource
{
    protected static ?string $model = Branch::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';
    protected static ?string $navigationGroup = '3. Administración';
    protected static ?int $navigationSort = 2;
    protected static ?string $modelLabel = 'Sucursal';

    public static function form(\Filament\Forms\Form $form): \Filament\Forms\Form
    {
        return $form
            ->schema([
                \Filament\Forms\Components\Section::make('Información del Local')
                    ->schema([
                        \Filament\Forms\Components\TextInput::make('name')
                            ->label('Nombre de la Sucursal (Ej: Sede Principal)')
                            ->required(),

                        \Filament\Forms\Components\TextInput::make('sunat_code')
                            ->label('Código de Establecimiento (SUNAT)')
                            ->default('0000')
                            ->maxLength(4)
                            ->required()
                            ->helperText('Usa "0000" para el local principal. Para sucursales usa 0001, 0002, etc.'),

                        \Filament\Forms\Components\Toggle::make('is_active')
                            ->label('¿Activa?')
                            ->default(true),
                    ])->columns(2),

                \Filament\Forms\Components\Section::make('Ubicación Geográfica (SUNAT)')
                    ->schema([
                        // 🌟 SELECTOR DE DEPARTAMENTO 🌟
                        \Filament\Forms\Components\Select::make('departamento_id')
                            ->label('Departamento')
                            ->options(function() {
                                $dbOptions = [];
                                if (Schema::hasTable('ubi_regiones')) {
                                    $dbOptions = DB::table('ubi_regiones')->pluck('nombre', 'id')->toArray();
                                }
                                
                                if (empty($dbOptions)) {
                                    return [
                                        '01' => 'AMAZONAS', '02' => 'ANCASH', '03' => 'APURIMAC', '04' => 'AREQUIPA',
                                        '05' => 'AYACUCHO', '06' => 'CAJAMARCA', '07' => 'CALLAO', '08' => 'CUSCO',
                                        '09' => 'HUANCAVELICA', '10' => 'HUANUCO', '11' => 'ICA', '12' => 'JUNIN',
                                        '13' => 'LA LIBERTAD', '14' => 'LAMBAYEQUE', '15' => 'LIMA', '16' => 'LORETO',
                                        '17' => 'MADRE DE DIOS', '18' => 'MOQUEGUA', '19' => 'PASCO', '20' => 'PIURA',
                                        '21' => 'PUNO', '22' => 'SAN MARTIN', '23' => 'TACNA', '24' => 'TUMBES',
                                        '25' => 'UCAYALI',
                                    ];
                                }
                                return $dbOptions;
                            })
                            ->searchable()
                            ->live()
                            ->dehydrated(false)
                            ->afterStateUpdated(function (Set $set, $state) {
                                $set('provincia_id', null);
                                $set('ubigeo', null);
                                if ($state) {
                                    $nombre = DB::table('ubi_regiones')->where('id', $state)->value('nombre');
                                    $set('department', $nombre);
                                }
                            }),

                        // 🌟 SELECTOR DE PROVINCIA 🌟
                        \Filament\Forms\Components\Select::make('provincia_id')
                            ->label('Provincia')
                            ->options(function(Get $get) {
                                $options = [];
                                if (Schema::hasTable('ubi_provincias')) {
                                    $options = DB::table('ubi_provincias')
                                        ->where('region_id', $get('departamento_id'))
                                        ->pluck('nombre', 'id')
                                        ->toArray();
                                }
                                
                                if (empty($options) && $get('departamento_id')) {
                                    return ['0001' => 'PROVINCIA GENERAL'];
                                }
                                return $options;
                            })
                            ->searchable()
                            ->live()
                            ->dehydrated(false)
                            ->afterStateUpdated(function (Set $set, $state) {
                                $set('ubigeo', null);
                                if ($state) {
                                    $nombre = DB::table('ubi_provincias')->where('id', $state)->value('nombre');
                                    $set('province', $nombre);
                                }
                            }),

                        // 🌟 SELECTOR DE DISTRITO (Atrapa el UBIGEO oculto) 🌟
                        \Filament\Forms\Components\Select::make('ubigeo')
                            ->label('Distrito')
                            ->options(function(Get $get) {
                                if (!Schema::hasTable('ubi_distritos')) return [];
                                return DB::table('ubi_distritos')
                                    ->where('provincia_id', $get('provincia_id'))
                                    ->pluck('nombre', 'id');
                            })
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function (Set $set, $state) {
                                if ($state) {
                                    $nombre = DB::table('ubi_distritos')->where('id', $state)->value('nombre');
                                    $set('district', $nombre);
                                }
                            }),

                        \Filament\Forms\Components\TextInput::make('address')
                            ->label('Dirección exacta (Av, Calle, Nro)')
                            ->required()
                            ->columnSpanFull(),

                        // Campos ocultos para guardar los nombres de texto en la BD
                        \Filament\Forms\Components\Hidden::make('department'),
                        \Filament\Forms\Components\Hidden::make('province'),
                        \Filament\Forms\Components\Hidden::make('district'),

                    ])->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Sucursal')
                    ->searchable(),
                Tables\Columns\TextColumn::make('address')
                    ->label('Dirección')
                    ->searchable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Estado')
                    ->boolean(),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->hasRole('super_admin') ?? false;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (!auth()->user()?->hasRole('super_admin')) {
            $userBranchId = auth()->user()?->branch_id ?? 1;
            $query->where('id', $userBranchId);
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
            'index' => Pages\ListBranches::route('/'),
            'create' => Pages\CreateBranch::route('/create'),
            'edit' => Pages\EditBranch::route('/{record}/edit'),
        ];
    }
}
