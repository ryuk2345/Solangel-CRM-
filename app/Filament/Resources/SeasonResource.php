<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SeasonResource\Pages;
use App\Models\Season;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SeasonResource extends Resource
{
    protected static ?string $model = Season::class;
    protected static ?string $cluster = \App\Filament\Clusters\Catalogs::class;
    protected static ?string $navigationIcon = 'heroicon-o-sun';
    protected static ?string $navigationGroup = null;
    protected static ?int $navigationSort = 4;
    protected static ?string $modelLabel = 'Temporada';
    protected static ?string $pluralModelLabel = 'Temporadas';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('Temporada / colección')->placeholder('Ej: Verano 2027')
                ->required()->unique(ignoreRecord: true),
            Forms\Components\Toggle::make('is_active')->label('Activa')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Temporada')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('products_count')->label('Modelos')->counts('products'),
                Tables\Columns\IconColumn::make('is_active')->label('Activa')->boolean(),
            ])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageSeasons::route('/')];
    }
}
