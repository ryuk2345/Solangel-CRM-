<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CompanySettingResource\Pages;
use App\Models\CompanySetting;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CompanySettingResource extends Resource
{
    protected static ?string $model = CompanySetting::class;
    protected static ?string $cluster = \App\Filament\Clusters\CompanyAndSunat::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office';
    protected static ?string $navigationGroup = null;
    protected static ?int $navigationSort = 1;
    protected static ?string $modelLabel = 'Empresa';
    protected static ?string $pluralModelLabel = 'Empresa';
    protected static ?string $navigationLabel = 'Empresa';

    // 🌟 ESTO ES CLAVE: Evita que aparezca en el menú si no es a través de getNavigationUrl
    public static function shouldRegisterNavigation(): bool
    {
        return true;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Tabs::make('Configuración')
                    ->tabs([
                        Forms\Components\Tabs\Tab::make('Datos de la Empresa')
                            ->icon('heroicon-o-building-office')
                            ->schema([
                                Forms\Components\Grid::make(2)
                                    ->schema([
                                        Forms\Components\TextInput::make('name')
                                            ->label('Razón Social / Nombre Comercial')
                                            ->required()
                                            ->placeholder('Ej. BOUTIQUE SAC')
                                            ->maxLength(255),

                                        Forms\Components\TextInput::make('ruc')
                                            ->label('RUC')
                                            ->required()
                                            ->length(11)
                                            ->placeholder('Ej. 20000000001')
                                            ->maxLength(255),

                                        Forms\Components\TextInput::make('address_principal')
                                            ->label('Dirección Principal / Sede Matriz')
                                            ->required()
                                            ->placeholder('Ej. Av. Larco 123, Miraflores')
                                            ->columnSpanFull()
                                            ->maxLength(255),

                                        Forms\Components\TextInput::make('phone')
                                            ->label('Celular de Contacto')
                                            ->tel()
                                            ->prefix('+51'),

                                        Forms\Components\TextInput::make('email')
                                            ->label('Correo Electrónico')
                                            ->email(),
                                    ]),
                            ]),

                        Forms\Components\Tabs\Tab::make('Credenciales SUNAT')
                            ->icon('heroicon-o-key')
                            ->schema([
                                Forms\Components\Grid::make(2)
                                    ->schema([
                                        Forms\Components\TextInput::make('sol_user')
                                            ->label('Usuario SOL')
                                            ->placeholder('Ej. MODDATOS')
                                            ->maxLength(255),

                                        Forms\Components\TextInput::make('sol_pass')
                                            ->label('Clave SOL')
                                            ->password()
                                            ->dehydrated(fn ($state) => filled($state))
                                            ->placeholder('••••••••')
                                            ->maxLength(255),

                                        Forms\Components\FileUpload::make('cert_path')
                                            ->label('Certificado Digital (.pfx / .p12)')
                                            ->acceptedFileTypes(['application/x-pkcs12', '.pfx', '.p12'])
                                            ->disk('local')
                                            ->directory('sunat/certs')
                                            ->placeholder('Sube tu archivo .pfx o .p12'),

                                        Forms\Components\TextInput::make('cert_password')
                                            ->label('Contraseña del Certificado')
                                            ->password()
                                            ->dehydrated(fn ($state) => filled($state))
                                            ->placeholder('Por defecto: 123456')
                                            ->maxLength(255),

                                        Forms\Components\TextInput::make('client_id')
                                            ->label('Client ID (API SUNAT)')
                                            ->placeholder('Opcional')
                                            ->maxLength(255),

                                        Forms\Components\TextInput::make('client_secret')
                                            ->label('Client Secret (API SUNAT)')
                                            ->password()
                                            ->dehydrated(fn ($state) => filled($state))
                                            ->placeholder('Opcional')
                                            ->maxLength(255),

                                        Forms\Components\Toggle::make('sunat_sandbox')
                                            ->label('Entorno de Pruebas (Beta SUNAT)')
                                            ->helperText('Actívalo para enviar facturas y boletas en modo de prueba (sin validez legal).')
                                            ->default(true)
                                            ->columnSpanFull(),
                                    ]),
                            ]),

                        Forms\Components\Tabs\Tab::make('Ubicación (SUNAT)')
                            ->icon('heroicon-o-map-pin')
                            ->schema([
                                Forms\Components\Grid::make(2)
                                    ->schema([
                                        Forms\Components\TextInput::make('ubigueo')
                                            ->label('Código de Ubigueo')
                                            ->required()
                                            ->default('150101')
                                            ->placeholder('Ej. 150101')
                                            ->maxLength(6),

                                        Forms\Components\TextInput::make('department')
                                            ->label('Departamento')
                                            ->required()
                                            ->default('LIMA')
                                            ->maxLength(255),

                                        Forms\Components\TextInput::make('province')
                                            ->label('Provincia')
                                            ->required()
                                            ->default('LIMA')
                                            ->maxLength(255),

                                        Forms\Components\TextInput::make('district')
                                            ->label('Distrito')
                                            ->required()
                                            ->default('LIMA')
                                            ->maxLength(255),
                                    ]),
                            ]),

                        Forms\Components\Tabs\Tab::make('Identidad Visual')
                            ->icon('heroicon-o-photo')
                            ->schema([
                                Forms\Components\FileUpload::make('logo')
                                    ->label('Logo de la Boutique')
                                    ->image()
                                    ->directory('company')
                                    ->columnSpanFull(),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            // Lo devolvemos a la normalidad (la magia de redirección ocurre en ListCompanySettings.php)
            'index' => Pages\ListCompanySettings::route('/'),
            'create' => Pages\CreateCompanySetting::route('/create'),
            'edit' => Pages\EditCompanySetting::route('/{record}/edit'),
        ];
    }

    // 🌟 Impedimos que se creen más registros si ya existe uno
    public static function canCreate(): bool
    {
        return CompanySetting::count() < 1;
    }
}
