<?php

namespace App\Filament\Clusters;

use Filament\Clusters\Cluster;

class UsersAndRoles extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-users';
    protected static ?string $navigationGroup = 'Configuración';
    protected static ?string $navigationLabel = 'Usuarios y roles';
    protected static ?string $clusterBreadcrumb = 'Usuarios y roles';
    protected static ?int $navigationSort = 3;
}
