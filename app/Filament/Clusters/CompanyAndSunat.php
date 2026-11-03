<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;

class CompanyAndSunat extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';
    protected static ?string $navigationGroup = 'Configuración';
    protected static ?string $navigationLabel = 'Empresa y SUNAT';
    protected static ?string $clusterBreadcrumb = 'Empresa y SUNAT';
    protected static ?int $navigationSort = 2;
}
