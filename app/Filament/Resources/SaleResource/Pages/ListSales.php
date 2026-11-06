<?php

namespace App\Filament\Resources\SaleResource\Pages;

use App\Filament\Resources\QuotationResource;
use App\Filament\Resources\SaleResource;
use App\Filament\Resources\WholesalePriceRequestResource;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\WholesalePriceRequest;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;

class ListSales extends ListRecords
{
    protected static string $resource = SaleResource::class;

    protected function getHeaderActions(): array
    {
        $pendingApprovals = WholesalePriceRequest::where('status', 'pendiente')->count();

        return [
            Actions\Action::make('wholesale_requests')
                ->label('Aprobaciones de precio')
                ->icon('heroicon-o-shield-check')
                ->color($pendingApprovals > 0 ? 'warning' : 'gray')
                ->badge($pendingApprovals > 0 ? (string) $pendingApprovals : null)
                ->badgeColor('warning')
                ->url(fn () => WholesalePriceRequestResource::getUrl('index'))
                ->visible(fn () => auth()->user()?->can('view_any_wholesale::price::request') ?? false),

            Actions\Action::make('create_quotation')
                ->label('Nueva cotización')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->url(fn () => QuotationResource::getUrl('create'))
                ->visible(fn () => auth()->user()?->can('create_quotation') ?? false),

            Actions\CreateAction::make()->label('Nueva venta'),
        ];
    }

    public function mount(): void
    {
        parent::mount();

        if (request()->filled('activeTab')) {
            $this->activeTab = request()->query('activeTab');
        } elseif (request()->boolean('con_notas')) {
            $this->activeTab = 'con_notas';
        }
    }

    public function getTabs(): array
    {
        $isSuperAdmin = auth()->user()?->seesAllBranches();
        $userBranchId = auth()->user()?->branch_id ?? 1;

        $creditCount = Sale::where('payment_condition', 'credito')
            ->where('credit_balance', '>', 0)
            ->whereNotIn('sunat_status', ['anulado', 'rechazado'])
            ->when(!$isSuperAdmin, fn ($q) => $q->where('branch_id', $userBranchId))
            ->count();

        $notesCount = Sale::where(fn ($q) => $q->whereNotNull('credit_note_serie')->orWhere('sunat_status', 'anulado'))
            ->when(!$isSuperAdmin, fn ($q) => $q->where('branch_id', $userBranchId))
            ->count();

        $quoteCount = Quotation::where('status', 'pendiente')
            ->when(!$isSuperAdmin, fn ($q) => $q->where('branch_id', $userBranchId))
            ->count();

        return [
            'todas' => Tab::make('Todas'),

            'por_cobrar' => Tab::make('Por cobrar')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('payment_condition', 'credito')
                    ->where('credit_balance', '>', 0)
                    ->whereNotIn('sunat_status', ['anulado', 'rechazado']))
                ->badge($creditCount ?: null)
                ->badgeColor('warning'),

            'con_notas' => Tab::make('Con nota de crédito')
                ->modifyQueryUsing(fn (Builder $query) => $query->where(fn ($q) => $q->whereNotNull('credit_note_serie')->orWhere('sunat_status', 'anulado')))
                ->badge($notesCount ?: null)
                ->badgeColor('danger'),

            'cotizaciones' => Tab::make('Cotizaciones')
                ->badge($quoteCount ?: null)
                ->badgeColor('info'),
        ];
    }

    public function updatedActiveTab(): void
    {
        if ($this->activeTab === 'cotizaciones') {
            $this->redirect(QuotationResource::getUrl());
        }
    }
}