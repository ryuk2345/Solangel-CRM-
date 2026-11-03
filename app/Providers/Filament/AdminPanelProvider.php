<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Illuminate\Support\HtmlString;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()

            // --- DISEÑO DE LA BOUTIQUE ---
            ->brandName('RWAREHOUSE ADMIN')
            ->brandLogo(fn () => ($cs = \App\Models\CompanySetting::first()) && $cs->logo
                ? asset('storage/' . $cs->logo)
                : null
            )
            ->brandLogoHeight('2.5rem')
            ->font('Plus Jakarta Sans')

            // 🌟 ACTIVAR EL BOTÓN DE MODO OSCURO (Aparecerá arriba a la derecha)
            ->darkMode(true)

            // Avisos dentro del sistema (por ejemplo: aprobaciones de precio mayorista pendientes)
            ->databaseNotifications()
            ->databaseNotificationsPolling('20s')

            ->colors([
                'primary' => '#e11d48', // Tu Rojo/Rosa vibrante
                'danger' => '#b91c1c',
                'gray' => Color::Gray,
                'info' => Color::Blue,
                'success' => Color::Emerald,
                'warning' => Color::Orange,
            ])
            ->navigationGroups([
                '1. Ventas y Cajas',
                '2. Catálogo e Inventario',
                '3. Administración',
                '4. Configuración',
            ])

            // 🌟 CSS INTELIGENTE Y PRESTIGIOSO PARA EL PANEL 🌟
            ->renderHook(
                'panels::head.done',
                fn() => new HtmlString('
                    <style>
                        /* Barra de desplazamiento (Scrollbar) premium */
                        ::-webkit-scrollbar {
                            width: 6px;
                            height: 6px;
                        }
                        ::-webkit-scrollbar-track {
                            background: transparent;
                        }
                        ::-webkit-scrollbar-thumb {
                            background: #cbd5e1;
                            border-radius: 10px;
                        }
                        .dark ::-webkit-scrollbar-thumb {
                            background: #334155;
                        }
                        ::-webkit-scrollbar-thumb:hover {
                            background: #94a3b8;
                        }

                        /* Estilos del sidebar y links activos */
                        .fi-sidebar {
                            transition: all 0.3s ease;
                        }
                        .dark .fi-sidebar {
                            background-color: #0d0e12 !important;
                            border-right: 1px solid #1e293b;
                        }
                        .dark .fi-main-ctn {
                            background-color: #07080a !important;
                        }
                        
                        /* Contenedores y secciones redondeadas y limpias */
                        .dark .fi-section, .dark .fi-ta-ctn {
                            background-color: #12141c !important;
                            border: 1px solid #1e293b !important;
                            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -2px rgba(0,0,0,0.1) !important;
                        }
                        .fi-section, .fi-ta-ctn {
                            border-radius: 12px !important;
                        }

                        /* Item Activo del menú con barra lateral roja */
                        .fi-sidebar-item-active {
                            background-color: rgba(225, 29, 72, 0.08) !important;
                            border-left: 3px solid #e11d48 !important;
                            border-radius: 0 8px 8px 0 !important;
                        }
                        .fi-sidebar-item-active * {
                            color: #e11d48 !important;
                            font-weight: 600 !important;
                        }

                        /* Transiciones elegantes */
                        .fi-sidebar-nav-link, .fi-btn, .fi-ta-row, .fi-badge {
                            transition: all 0.2s ease-in-out !important;
                        }

                        /* Botón primario pulido */
                        .fi-btn-color-primary {
                            background-color: #e11d48 !important;
                            border-radius: 8px !important;
                            font-weight: 600 !important;
                        }
                        .fi-btn-color-primary:hover {
                            background-color: #be123c !important;
                            box-shadow: 0 4px 12px rgba(225, 29, 72, 0.3) !important;
                        }
                        
                        /* Filas de tabla con hover suave */
                        .fi-ta-row:hover {
                            background-color: rgba(241, 245, 249, 0.6) !important;
                        }
                        .dark .fi-ta-row:hover {
                            background-color: rgba(30, 41, 59, 0.5) !important;
                        }

                        /* Inputs de formulario elegantes */
                        .fi-fo-text-input input, .fi-fo-select select, .fi-fo-textarea textarea {
                            border-radius: 8px !important;
                        }
                    </style>
                ')
            )

            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                \App\Filament\Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
                \App\Filament\Widgets\StatsOverview::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make(),
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
            
    }
}
