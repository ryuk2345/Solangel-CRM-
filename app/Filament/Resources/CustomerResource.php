<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerResource\Pages;
use App\Models\Customer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $modelLabel = 'Cliente';
    protected static ?string $pluralModelLabel = 'Clientes';
    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $navigationGroup = '1. Ventas y Cajas'; // Respetando tu grupo organizado

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('document_type')
                    ->label('Tipo de Documento')
                    ->options([
                        '1' => 'DNI',
                        '6' => 'RUC',
                        '4' => 'Carné de Extranjería',
                    ])
                    ->required()
                    ->live() // 🌟 Añadido para que escuche cambios
                    ->afterStateUpdated(fn(Forms\Set $set) => $set('document_number', null)),

                Forms\Components\TextInput::make('document_number')
                    ->label('Número de Documento')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(15)
                    ->suffixAction(
                        Forms\Components\Actions\Action::make('search')
                            ->icon('heroicon-o-magnifying-glass')
                            ->label('Buscar en SUNAT')
                            ->action(function (Forms\Set $set, Forms\Get $get) {
                                $tipo = $get('document_type');
                                $num  = $get('document_number');
                                $token = env('API_PERU_TOKEN');

                                if (!$tipo || !$num) {
                                    Notification::make()->title('Ingresa el tipo y número de documento primero')->warning()->send();
                                    return;
                                }
                                if (!$token) {
                                    Notification::make()->title('API no configurada')->body('Falta la variable API_PERU_TOKEN en el archivo .env del servidor.')->warning()->send();
                                    return;
                                }
                                if (!in_array($tipo, ['1', '6'])) {
                                    Notification::make()->title('Búsqueda no disponible para este documento')->warning()->send();
                                    return;
                                }

                                try {
                                    $url = $tipo == '1'
                                        ? "https://api.apis.net.pe/v1/dni?numero={$num}"
                                        : "https://api.apis.net.pe/v1/ruc?numero={$num}";

                                    $res = Http::withToken($token)
                                        ->withoutVerifying()
                                        ->timeout(10)
                                        ->get($url);

                                    if ($res->successful()) {
                                        $data = $res->json();
                                        if ($tipo == '1') {
                                            $nombre = trim(($data['nombres'] ?? '') . ' ' . ($data['apellidoPaterno'] ?? '') . ' ' . ($data['apellidoMaterno'] ?? ''));
                                            $set('name', $nombre);
                                        } else {
                                            $set('name', $data['nombre'] ?? '');
                                            $set('address', $data['direccion'] ?? '');
                                        }
                                        Notification::make()->title('Cliente Encontrado')->success()->send();
                                    } else {
                                        Notification::make()->title('Documento no encontrado en SUNAT')->warning()->send();
                                    }
                                } catch (\Throwable $e) {
                                    Notification::make()->title('Error al consultar la API')->body('Verifica tu conexión o el token API_PERU_TOKEN.')->danger()->send();
                                }
                            })
                    ),

                Forms\Components\TextInput::make('name')
                    ->label('Nombre / Razón Social')
                    ->required()
                    ->maxLength(255),

                Forms\Components\ToggleButtons::make('customer_type')
                    ->label('Tipo de cliente')
                    ->options(['minorista' => 'Minorista', 'mayorista' => 'Mayorista'])
                    ->icons(['minorista' => 'heroicon-o-user', 'mayorista' => 'heroicon-o-building-storefront'])
                    ->colors(['minorista' => 'gray', 'mayorista' => 'warning'])
                    ->default('minorista')
                    ->inline()
                    ->required()
                    ->helperText('Desde ' . \App\Models\CompanySetting::wholesaleMinQty() . ' prendas todos pagan precio mayorista. Un cliente mayorista puede llevar 1 prenda a precio mayorista solo con aprobación de un administrador.'),

                Forms\Components\TextInput::make('address')
                    ->label('Dirección Física')
                    ->maxLength(255)
                    ->columnSpanFull(), // Ocupa toda la fila

                Forms\Components\TextInput::make('email')
                    ->label('Correo Electrónico (Para enviar la factura)')
                    ->email()
                    ->maxLength(255),

                Forms\Components\TextInput::make('phone')
                    ->label('Teléfono / WhatsApp')
                    ->tel()
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('document_number')
                    ->label('Documento')
                    ->searchable(query: function (\Illuminate\Database\Eloquent\Builder $query, string $search): \Illuminate\Database\Eloquent\Builder {
                        $search = trim($search);
                        return $query->where('document_number', 'like', "{$search}%");
                    }),
                    
                Tables\Columns\TextColumn::make('name')
                    ->label('Cliente')
                    ->searchable(query: function (\Illuminate\Database\Eloquent\Builder $query, string $search): \Illuminate\Database\Eloquent\Builder {
                        $search = trim($search);
                        return $query->where('name', 'like', "%{$search}%");
                    })
                    ->wrap(), // 🌟 LA MAGIA: Rompe el texto largo en varias líneas
                    
                Tables\Columns\TextColumn::make('customer_type')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn ($state) => ucfirst($state))
                    ->color(fn ($state) => $state === 'mayorista' ? 'warning' : 'gray'),

                Tables\Columns\TextColumn::make('email')
                    ->label('Correo')
                    ->searchable()
                    ->wrap(), // También le ponemos wrap por si hay correos muy largos
                    
                Tables\Columns\TextColumn::make('phone')
                    ->label('Teléfono'),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('customer_type')
                    ->label('Tipo')
                    ->options(['minorista' => 'Minorista', 'mayorista' => 'Mayorista']),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // 🛡️ Vacío por seguridad: Nadie puede borrar clientes de golpe
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['document_number', 'name'];
    }

    public static function getGlobalSearchResultTitle(\Illuminate\Database\Eloquent\Model $record): string
    {
        /** @var Customer $record */
        return $record->getOptionLabel();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }
}