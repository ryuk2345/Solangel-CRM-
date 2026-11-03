<?php

namespace App\Filament\Resources\QuotationResource\Pages;

use App\Filament\Resources\QuotationResource;
use App\Filament\Resources\SaleResource;
use App\Filament\Resources\WholesalePriceRequestResource;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\WholesalePriceRequest;
use Filament\Actions;
use Filament\Resources\Components\Tab;
use Filament\Resources\Pages\ListRecords;

class ListQuotations extends ListRecords
{
    protected static string $resource = QuotationResource::class;

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

            Actions\Action::make('new_sale')
                ->label('Nueva venta')
                ->icon('heroicon-o-shopping-cart')
                ->color('gray')
                ->url(fn () => SaleResource::getUrl('create'))
                ->visible(fn () => auth()->user()?->can('create_sale') ?? false),

            Actions\CreateAction::make()->label('Nueva cotización'),
        ];
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
                ->badge($creditCount ?: null)
                ->badgeColor('warning'),
            'con_notas' => Tab::make('Con nota de crédito')
                ->badge($notesCount ?: null)
                ->badgeColor('danger'),
            'cotizaciones' => Tab::make('Cotizaciones')
                ->badge($quoteCount ?: null)
                ->badgeColor('info'),
        ];
    }

    public function getDefaultActiveTab(): string | int | null
    {
        return 'cotizaciones';
    }

    public function updatedActiveTab(): void
    {
        if ($this->activeTab !== 'cotizaciones') {
            $this->redirect(SaleResource::getUrl('index', ['activeTab' => $this->activeTab]));
        }
    }
}
