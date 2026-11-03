<?php

namespace App\Filament\Pages;

use App\Filament\Resources\SaleResource;
use App\Models\Sale;
use App\Services\CreditService;
use BezhanSalleh\FilamentShield\Traits\HasPageShield;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Cuentas por cobrar: ventas a crédito con saldo pendiente y vencidas (Etapa 8b). */
class AccountsReceivable extends Page implements HasTable
{
    use HasPageShield;
    use InteractsWithTable;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';
    protected static ?string $navigationGroup = '1. Ventas y Cajas';
    protected static ?string $navigationLabel = 'Cuentas por cobrar';
    protected static ?string $title = 'Cuentas por cobrar';
    protected static ?int $navigationSort = 6;
    protected static string $view = 'filament.pages.table-page';

    public static function getNavigationBadge(): ?string
    {
        $overdue = Sale::where('credit_balance', '>', 0)->whereNotIn('sunat_status', ['anulado', 'rechazado'])
            ->whereDate('due_date', '<', now()->toDateString())
            ->when(!auth()->user()?->seesAllBranches(), fn ($q) => $q->where('branch_id', auth()->user()?->branch_id ?? 1))
            ->count();

        return $overdue > 0 ? (string) $overdue : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    protected function baseQuery(): Builder
    {
        return Sale::query()
            ->with(['customer', 'branch'])
            ->where('credit_balance', '>', 0)
            ->whereNotIn('sunat_status', ['anulado', 'rechazado'])
            ->when(!auth()->user()?->seesAllBranches(), fn ($q) => $q->where('branch_id', auth()->user()?->branch_id ?? 1));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->baseQuery())
            ->defaultSort('due_date')
            ->columns([
                Tables\Columns\TextColumn::make('customer.name')->label('Cliente')->wrap()
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('customer', fn ($c) => $c->where('name', 'like', "%{$search}%")->orWhere('document_number', 'like', "{$search}%"))),
                Tables\Columns\TextColumn::make('serie')->label('Comprobante')
                    ->formatStateUsing(fn ($state, Sale $record) => "{$record->serie}-{$record->correlative}"),
                Tables\Columns\TextColumn::make('issue_date')->label('Fecha')->date('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('due_date')->label('Vence')->date('d/m/Y')->sortable()
                    ->color(fn (Sale $record) => CreditService::isOverdue($record) ? 'danger' : null)
                    ->description(fn (Sale $record) => CreditService::isOverdue($record)
                        ? 'Vencida hace ' . $record->due_date->diffInDays(now()->startOfDay()) . ' días' : null),
                Tables\Columns\TextColumn::make('total')->label('Total')->money('PEN'),
                Tables\Columns\TextColumn::make('credit_balance')->label('Saldo')->money('PEN')->weight('bold')->sortable(),
                Tables\Columns\TextColumn::make('branch.name')->label('Tienda')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\Filter::make('vencidas')->label('Solo vencidas')->toggle()
                    ->query(fn (Builder $query) => $query->whereDate('due_date', '<', now()->toDateString())),
            ])
            ->actions([
                SaleResource::abonoAction(Tables\Actions\Action::make('abono')),
            ])
            ->headerActions([
                Tables\Actions\Action::make('exportar')
                    ->label('Exportar a Excel (CSV)')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn () => auth()->user()?->can('export_reports'))
                    ->action(fn () => $this->exportCsv()),
            ])
            ->emptyStateHeading('No hay ventas a crédito pendientes')
            ->emptyStateIcon('heroicon-o-check-circle');
    }

    public function exportCsv(): StreamedResponse
    {
        $rows = $this->baseQuery()->orderBy('due_date')->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Cliente', 'Documento', 'Comprobante', 'Fecha', 'Vence', 'Total', 'Saldo', 'Vencida'], ';');
            foreach ($rows as $s) {
                fputcsv($out, [
                    $s->customer?->name, $s->customer?->document_number, "{$s->serie}-{$s->correlative}",
                    $s->issue_date?->format('d/m/Y'), $s->due_date?->format('d/m/Y'),
                    number_format((float) $s->total, 2, '.', ''), number_format((float) $s->credit_balance, 2, '.', ''),
                    CreditService::isOverdue($s) ? 'Sí' : 'No',
                ], ';');
            }
            fclose($out);
        }, 'cuentas-por-cobrar-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
