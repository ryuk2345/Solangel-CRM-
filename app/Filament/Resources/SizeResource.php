<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SizeResource\Pages;
use App\Models\Size;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SizeResource extends Resource
{
    protected static ?string $model = Size::class;
    protected static ?string $cluster = \App\Filament\Clusters\Catalogs::class;
    protected static ?string $navigationIcon = 'heroicon-o-arrows-pointing-out';
    protected static ?string $navigationGroup = null;
    protected static ?int $navigationSort = 2;
    protected static ?string $modelLabel = 'Talla';
    protected static ?string $pluralModelLabel = 'Tallas';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label('Talla')->required()->unique(ignoreRecord: true)->maxLength(20),
            Forms\Components\TextInput::make('sort_order')->label('Orden')
                ->helperText('Define el orden en pantallas y reportes (S antes que M, 28 antes que 30).')
                ->numeric()->default(0),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                Tables\Columns\TextColumn::make('name')->label('Talla')->searchable()->badge(),
                Tables\Columns\TextColumn::make('sort_order')->label('Orden')->sortable(),
                Tables\Columns\TextColumn::make('variants_count')->label('Variantes')->counts('variants'),
            ])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageSizes::route('/')];
    }
}
