<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $modelLabel = 'Usuario';
    protected static ?string $pluralModelLabel = 'Usuarios del Sistema';
    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationGroup = '4. Configuración';
    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        $isSuperAdmin = auth()->user()?->seesAllBranches() ?? false;

        return $form
            ->schema([
                Forms\Components\TextInput::make('name')
                    ->label('Nombre Completo')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('email')
                    ->label('Correo Electrónico (Usuario)')
                    ->email()
                    ->unique(ignoreRecord: true)
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('password')
                    ->label('Contraseña')
                    ->password()
                    ->dehydrateStateUsing(fn($state) => \Illuminate\Support\Facades\Hash::make($state))
                    ->dehydrated(fn($state) => filled($state))
                    ->required(fn(string $context): bool => $context === 'create')
                    ->maxLength(255),

                Forms\Components\Select::make('branch_id')
                    ->relationship('branch', 'name')
                    ->label('Sucursal Asignada')
                    ->required()
                    ->default(fn () => auth()->user()?->branch_id ?? 1)
                    ->disabled(!$isSuperAdmin)
                    ->dehydrated()
                    ->helperText('Tienda a la que pertenece el usuario. Define qué inventario, cajas y ventas opera.')
                    ->preload()
                    ->searchable(),

                Forms\Components\Select::make('roles')
                    ->label('Rol')
                    ->helperText('Administrador, vendedor, almacenero o contador.')
                    // super_admin es el rol técnico del proveedor: solo otro super_admin puede asignarlo
                    ->relationship('roles', 'name', fn (Builder $query) => auth()->user()?->hasRole('super_admin')
                        ? $query
                        : $query->where('name', '!=', 'super_admin'))
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->disabled(!$isSuperAdmin),
            ]);
    }

    public static function table(Table $table): Table
    {
        $isSuperAdmin = auth()->user()?->seesAllBranches() ?? false;

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Nombre')
                    ->searchable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Correo')
                    ->searchable(),

                Tables\Columns\TextColumn::make('branch.name')
                    ->label('Sucursal')
                    ->badge()
                    ->color('success')
                    ->sortable()
                    ->searchable(),

                Tables\Columns\TextColumn::make('roles.name')
                    ->label('Roles Asignados')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Fecha de Creación')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('branch_id')
                    ->relationship('branch', 'name')
                    ->label('Sucursal')
                    ->searchable()
                    ->preload()
                    ->visible($isSuperAdmin),
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

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['branch', 'roles']);

        // El usuario técnico super_admin no se muestra al cliente
        if (!auth()->user()?->hasRole('super_admin')) {
            $query->whereDoesntHave('roles', fn ($r) => $r->where('name', 'super_admin'));
        }

        // Solo el administrador ve los usuarios de todas las tiendas
        if (!auth()->user()?->seesAllBranches()) {
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
            'index'  => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit'   => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
