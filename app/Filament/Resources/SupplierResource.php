<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupplierResource\Pages;
use App\Models\Supplier;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;
    protected static ?string $navigationGroup = '2. Catálogo e Inventario';
    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';
    protected static ?string $navigationLabel = 'Proveedores';
    protected static ?string $modelLabel = 'Proveedor';
    protected static ?string $pluralModelLabel = 'Proveedores';
    protected static ?int $navigationSort = 7;

    /** Campos reutilizados también al crear un proveedor desde la compra. */
    public static function fields(): array
    {
        return [
            Forms\Components\Select::make('document_type')->label('Documento')
                ->options(['6' => 'RUC', '1' => 'DNI'])->default('6')->required(),
            Forms\Components\TextInput::make('document_number')->label('Número')->maxLength(15)
                ->unique(table: 'suppliers', column: 'document_number', ignoreRecord: true),
            Forms\Components\TextInput::make('name')->label('Razón social / nombre')->required()->maxLength(255),
            Forms\Components\TextInput::make('contact_name')->label('Contacto')->maxLength(120),
            Forms\Components\TextInput::make('phone')->label('Teléfono / WhatsApp')->tel()->maxLength(30),
            Forms\Components\TextInput::make('email')->label('Correo')->email()->maxLength(120),
            Forms\Components\TextInput::make('address')->label('Dirección')->maxLength(255),
        ];
    }

    public static function form(Form $form): Form
    {
        return $form->columns(2)->schema([
            ...static::fields(),
            Forms\Components\Toggle::make('is_active')->label('Activo')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('document_number')->label('RUC / DNI')->searchable(),
                Tables\Columns\TextColumn::make('name')->label('Proveedor')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('contact_name')->label('Contacto'),
                Tables\Columns\TextColumn::make('phone')->label('Teléfono'),
                Tables\Columns\TextColumn::make('purchases_count')->counts('purchases')->label('Compras'),
                Tables\Columns\IconColumn::make('is_active')->label('Activo')->boolean(),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageSuppliers::route('/')];
    }
}
