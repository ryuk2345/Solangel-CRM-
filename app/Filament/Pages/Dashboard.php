<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    public static function canView(): bool
    {
        // Solo super_admin o usuarios con el permiso específico de la página Dashboard
        return auth()->user()->seesAllBranches() || auth()->user()->can('page_Dashboard');
    }
}
