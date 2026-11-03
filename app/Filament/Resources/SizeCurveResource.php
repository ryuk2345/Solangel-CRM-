<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SizeCurveResource\Pages;
use App\Models\Size;
use App\Models\SizeCurve;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SizeCurveResource extends Resource
{
    protected static ?string $model = SizeCurve::class;
    protected static ?string $cluster = \App\Filament\Clusters\Catalogs::class;
    protected static ?string $navigationIcon = 'heroicon-o-bars-3-bottom-left';
    protected static ?string $navigationGroup = null;
    protected static ?int $navigationSort = 3;
    protected static ?string $modelLabel = 'Curva de tallas';
    protected static ?string $pluralModelLabel = 'Curvas de tallas';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('Nombre')->placeholder('Ej: Letras S–XL')
                ->required()->unique(ignoreRecord: true),
            Forms\Components\Select::make('sizes')->label('Tallas')
                ->relationship('sizes', 'name', fn ($query) => $query->orderBy('sort_order'))
                ->multiple()->preload()->required()
                ->helperText('Se ordenan solas según el orden de cada talla.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Curva')->searchable(),
                Tables\Columns\TextColumn::make('sizes.name')->label('Tallas')->badge(),
            ])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageSizeCurves::route('/')];
    }
}
