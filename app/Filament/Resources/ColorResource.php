<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ColorResource\Pages;
use App\Models\Color;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ColorResource extends Resource
{
    protected static ?string $model = Color::class;
    protected static ?string $navigationIcon = 'heroicon-o-swatch';
    protected static ?string $navigationGroup = '2. Catálogo e Inventario';
    protected static ?int $navigationSort = 20;
    protected static ?string $modelLabel = 'Color';
    protected static ?string $pluralModelLabel = 'Colores';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('Nombre')->required()->unique(ignoreRecord: true)->maxLength(60),
            Forms\Components\TextInput::make('code')->label('Código para el SKU')->helperText('3 a 6 letras, por ejemplo NEG o AZM.')
                ->required()->unique(ignoreRecord: true)->maxLength(6)->regex('/^[A-Za-z0-9]{2,6}$/'),
            Forms\Components\ColorPicker::make('hex')->label('Color de muestra'),
            Forms\Components\TextInput::make('sort_order')->label('Orden')->numeric()->default(0),
            Forms\Components\Toggle::make('is_active')->label('Activo')->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                Tables\Columns\ColorColumn::make('hex')->label(''),
                Tables\Columns\TextColumn::make('name')->label('Color')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('code')->label('Código')->badge(),
                Tables\Columns\TextColumn::make('variants_count')->label('Variantes')->counts('variants'),
                Tables\Columns\IconColumn::make('is_active')->label('Activo')->boolean(),
            ])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageColors::route('/')];
    }
}
