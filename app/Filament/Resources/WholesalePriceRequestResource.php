<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WholesalePriceRequestResource\Pages;
use App\Models\WholesalePriceRequest;
use App\Services\WholesaleApprovalService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Aprobaciones de precio mayorista (decisión 6): el administrador ve las solicitudes
 * de los vendedores y las aprueba o rechaza con su PIN.
 */
class WholesalePriceRequestResource extends Resource
{
    protected static ?string $model = WholesalePriceRequest::class;

    protected static ?string $navigationGroup = '1. Ventas y Cajas';
    protected static ?string $navigationIcon = 'heroicon-o-shield-check';
    protected static ?string $navigationLabel = 'Aprobaciones de precio';
    protected static ?string $modelLabel = 'Aprobación de precio mayorista';
    protected static ?string $pluralModelLabel = 'Aprobaciones de precio mayorista';
    protected static ?int $navigationSort = 5;

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->where('status', 'pendiente')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function canCreate(): bool
    {
        return false; // Se piden desde la venta
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->poll('20s')
            ->columns([
                Tables\Columns\TextColumn::make('created_at')->label('Pedida')->dateTime('d/m/Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('status')->label('Estado')->badge()
                    ->formatStateUsing(fn ($state) => ucfirst($state))
                    ->color(fn ($state) => match ($state) {
                        'pendiente' => 'warning',
                        'aprobada' => 'success',
                        'usada' => 'info',
                        'rechazada' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('customer.name')->label('Cliente')->wrap()->searchable(),
                Tables\Columns\TextColumn::make('items_summary')->label('Prendas')->wrap(),
                Tables\Columns\TextColumn::make('requester.name')->label('Vendedor'),
                Tables\Columns\TextColumn::make('branch.name')->label('Tienda')->toggleable(),
                Tables\Columns\TextColumn::make('decider.name')->label('Resuelta por')->placeholder('—'),
                Tables\Columns\TextColumn::make('sale_id')->label('Venta')
                    ->formatStateUsing(fn ($state, WholesalePriceRequest $record) => $record->sale ? "{$record->sale->serie}-{$record->sale->correlative}" : '—')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('note')->label('Nota')->wrap()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label('Estado')
                    ->options(['pendiente' => 'Pendiente', 'aprobada' => 'Aprobada', 'usada' => 'Usada', 'rechazada' => 'Rechazada'])
                    ->default('pendiente'),
            ])
            ->actions([
                Tables\Actions\Action::make('aprobar')
                    ->label('Aprobar')
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->visible(fn (WholesalePriceRequest $record) => $record->status === 'pendiente' && auth()->user()?->can('authorize_wholesale_price'))
                    ->form([
                        Forms\Components\TextInput::make('pin')->label('Tu PIN de aprobación')->password()->required(),
                    ])
                    ->action(function (WholesalePriceRequest $record, array $data) {
                        try {
                            WholesaleApprovalService::approve($record, auth()->user(), $data['pin']);
                            Notification::make()->title('Aprobada. Se avisó al vendedor.')->success()->send();
                        } catch (ValidationException $e) {
                            Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();
                        }
                    }),
                Tables\Actions\Action::make('rechazar')
                    ->label('Rechazar')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->visible(fn (WholesalePriceRequest $record) => $record->status === 'pendiente' && auth()->user()?->can('authorize_wholesale_price'))
                    ->form([
                        Forms\Components\TextInput::make('note')->label('Motivo')->maxLength(150),
                        Forms\Components\TextInput::make('pin')->label('Tu PIN de aprobación')->password()->required(),
                    ])
                    ->action(function (WholesalePriceRequest $record, array $data) {
                        try {
                            WholesaleApprovalService::reject($record, auth()->user(), $data['pin'], $data['note'] ?? null);
                            Notification::make()->title('Rechazada. Se avisó al vendedor.')->send();
                        } catch (ValidationException $e) {
                            Notification::make()->title(collect($e->errors())->flatten()->first())->danger()->send();
                        }
                    }),
            ])
            ->bulkActions([]);
    }

    /** El vendedor ve solo sus solicitudes; quien aprueba ve las de todas las tiendas. */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['customer', 'requester', 'decider', 'branch', 'sale']);
        $user = auth()->user();

        if ($user && !$user->can('authorize_wholesale_price')) {
            $query->where('requested_by', $user->id);
        }

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWholesalePriceRequests::route('/'),
        ];
    }
}
