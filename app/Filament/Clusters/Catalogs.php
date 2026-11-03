<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;

class Catalogs extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-swatch';
    protected static ?string $navigationGroup = 'Configuración';
    protected static ?string $navigationLabel = 'Catálogos';
    protected static ?string $clusterBreadcrumb = 'Catálogos';
    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        return static::canAccessClusteredComponents();
    }
}
