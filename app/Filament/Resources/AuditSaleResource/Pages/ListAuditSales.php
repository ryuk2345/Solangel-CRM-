<?php

namespace App\Filament\Resources\AuditSaleResource\Pages;

use App\Filament\Resources\AuditSaleResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Components\Tab;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Sale;

class ListAuditSales extends ListRecords
{
    protected static string $resource = AuditSaleResource::class;

    protected function getHeaderActions(): array { return []; }

    public function getTabs(): array
    {
        $isSuperAdmin = auth()->user()?->hasRole('super_admin');
        $userBranchId = auth()->user()?->branch_id ?? 1;

        $countQuery = fn (\Closure $callback) => \App\Models\Sale::query()
            ->when(!$isSuperAdmin, fn ($q) => $q->where('branch_id', $userBranchId))
            ->tap($callback)
            ->count();

        return [
            'todos' => Tab::make('Todo'),
            
            'boletas' => Tab::make('Boletas')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('serie', 'like', 'B%'))
                ->badge($countQuery(fn ($q) => $q->where('serie', 'like', 'B%')))
                ->badgeColor('success'),

            'facturas' => Tab::make('Facturas')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('serie', 'like', 'F%'))
                ->badge($countQuery(fn ($q) => $q->where('serie', 'like', 'F%')))
                ->badgeColor('info'),

            'notas' => Tab::make('Notas de Venta')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('serie', 'like', 'NV%'))
                ->badge($countQuery(fn ($q) => $q->where('serie', 'like', 'NV%')))
                ->badgeColor('gray'),

            'rechazados' => Tab::make('Rechazados SUNAT')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('sunat_status', 'rechazado'))
                ->badge($countQuery(fn ($q) => $q->where('sunat_status', 'rechazado')))
                ->badgeColor('danger'),

            'anulados' => Tab::make('Anulados')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('sunat_status', 'anulado'))
                ->badge($countQuery(fn ($q) => $q->where('sunat_status', 'anulado')))
                ->badgeColor('danger'),
        ];
    }
}