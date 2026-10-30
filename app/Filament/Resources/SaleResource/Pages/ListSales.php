<?php

namespace App\Filament\Resources\SaleResource\Pages;

use App\Filament\Resources\SaleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Filament\Resources\Components\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListSales extends ListRecords
{
    protected static string $resource = SaleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    public function mount(): void
    {
        parent::mount();

        if (request()->boolean('con_notas')) {
            $this->tableFilters['con_notas'] = ['isActive' => true];
        }
    }

    // 🌟 ESTA ES LA MAGIA DE LAS PESTAÑAS 🌟
    public function getTabs(): array
    {
        $isSuperAdmin = auth()->user()?->hasRole('super_admin');
        $userBranchId = auth()->user()?->branch_id ?? 1;

        $countQuery = fn (string $prefix) => \App\Models\Sale::where('serie', 'like', $prefix)
            ->when(!$isSuperAdmin, fn ($q) => $q->where('branch_id', $userBranchId))
            ->count();

        return [
            'todos' => Tab::make('Todos los Comprobantes'),
            
            'boletas' => Tab::make('Boletas')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('serie', 'like', 'B%'))
                ->badge($countQuery('B%')),

            'facturas' => Tab::make('Facturas')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('serie', 'like', 'F%'))
                ->badge($countQuery('F%'))
                ->badgeColor('info'),

            'notas' => Tab::make('Notas de Venta')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('serie', 'like', 'NV%'))
                ->badge($countQuery('NV%'))
                ->badgeColor('gray'),

            'rechazados' => Tab::make('Rechazados SUNAT')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('sunat_status', 'rechazado'))
                ->badge(
                    \App\Models\Sale::where('sunat_status', 'rechazado')
                        ->when(!$isSuperAdmin, fn ($q) => $q->where('branch_id', $userBranchId))
                        ->count()
                )
                ->badgeColor('danger'),
        ];
    }
}