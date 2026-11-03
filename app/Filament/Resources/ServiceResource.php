<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ServiceResource\Pages;
use App\Models\Product; // 🌟 APUNTA AL MISMO MODELO
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ServiceResource extends Resource
{
    protected static ?string $model = \App\Models\Service::class;
    protected static ?string $cluster = \App\Filament\Clusters\Catalogs::class;
    
    // 🌟 Icono de herramientas y menú separado
    protected static ?string $navigationIcon = 'heroicon-o-truck';
    protected static ?string $navigationGroup = null;
    protected static ?int $navigationSort = 7;
    protected static ?string $modelLabel = 'Delivery';
    protected static ?string $pluralModelLabel = 'Delivery';
    protected static ?string $navigationLabel = 'Delivery';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Detalles del Servicio')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nombre (ej. Delivery Lima Metropolitana)')
                            ->required()
                            ->maxLength(255),

                        Forms\Components\TextInput::make('sale_price')
                            ->label('Precio sugerido (S/)')
                            ->required()
                            ->numeric()
                            ->prefix('S/'),

                        Forms\Components\Textarea::make('description')
                            ->label('Descripción detallada')
                            ->columnSpanFull(),

                        // 🌟 CAMPOS INVISIBLES PERO OBLIGATORIOS PARA LA BD
                        Forms\Components\Hidden::make('is_service')->default(true),
                        Forms\Components\Hidden::make('unit_code')->default('ZZ'),
                        Forms\Components\Hidden::make('is_web_visible')->default(true),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Servicio')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('sale_price')
                    ->label('Precio')
                    ->money('PEN')
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('unit_code')
                    ->label('Unidad SUNAT')
                    ->badge()
                    ->color('warning'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    // 🌟 ESTO OCULTA LOS PRODUCTOS FÍSICOS DE ESTA PANTALLA
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('is_service', true);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    // 🌟 AHORA SÍ USA LAS PÁGINAS QUE GENERÓ LA CONSOLA
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListServices::route('/'),
            'create' => Pages\CreateService::route('/create'),
            'edit' => Pages\EditService::route('/{record}/edit'),
        ];
    }
}