<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\User;
use App\Models\WholesalePriceRequest;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Aprobación del precio mayorista para menos prendas que el mínimo (decisión 6):
 * el vendedor la pide, se avisa a los administradores en el sistema y uno de ellos
 * la aprueba o rechaza con su PIN. También puede aprobarse en el momento, en el punto de venta.
 */
class WholesaleApprovalService
{
    /** Administradores que pueden aprobar (rol administrador con el permiso). */
    public static function approvers(): Collection
    {
        return User::role('administrador')->get()
            ->filter(fn (User $u) => $u->can('authorize_wholesale_price'))
            ->values();
    }

    /** Valida que se pueda pedir: cliente mayorista y menos prendas que el mínimo. */
    public static function assertCanRequest(?int $customerId, array $items): Customer
    {
        $customer = Customer::find($customerId);
        if (!$customer || $customer->customer_type !== 'mayorista') {
            throw ValidationException::withMessages([
                'customer_id' => 'Solo se puede pedir precio mayorista para un cliente marcado como mayorista.',
            ]);
        }

        $garments = \App\Support\SaleCart::garments($items);
        $min = \App\Models\CompanySetting::wholesaleMinQty();
        if ($garments < 1) {
            throw ValidationException::withMessages(['items' => 'Agrega la prenda antes de pedir la aprobación.']);
        }
        if ($garments >= $min) {
            throw ValidationException::withMessages(['items' => "Con {$garments} prendas ya corresponde precio mayorista; no hace falta aprobación."]);
        }

        return $customer;
    }

    /** El vendedor pide la aprobación: queda pendiente y se avisa a los administradores. */
    public static function request(?int $customerId, ?int $branchId, array $items, User $requester, ?string $note = null): WholesalePriceRequest
    {
        $customer = self::assertCanRequest($customerId, $items);

        $request = WholesalePriceRequest::create([
            'branch_id' => $branchId,
            'customer_id' => $customer->id,
            'requested_by' => $requester->id,
            'status' => 'pendiente',
            'items' => self::snapshot($items),
            'note' => $note,
        ]);

        $approvers = self::approvers();
        if ($approvers->isNotEmpty()) {
            Notification::make()
                ->title('Aprobación pendiente: precio mayorista')
                ->body("{$requester->name} pide precio mayorista para {$customer->name}: {$request->items_summary}.")
                ->warning()
                ->icon('heroicon-o-shield-check')
                ->actions([
                    NotificationAction::make('revisar')
                        ->label('Revisar')
                        ->button()
                        ->url(\App\Filament\Resources\WholesalePriceRequestResource::getUrl('index')),
                ])
                ->sendToDatabase($approvers);
        }

        return $request;
    }

    /** Un administrador aprueba una solicitud pendiente con su PIN. */
    public static function approve(WholesalePriceRequest $request, User $approver, ?string $pin): WholesalePriceRequest
    {
        self::assertPin($approver, $pin);

        if ($request->status !== 'pendiente') {
            throw ValidationException::withMessages(['pin' => 'Esta solicitud ya fue resuelta.']);
        }

        $request->update(['status' => 'aprobada', 'decided_by' => $approver->id, 'decided_at' => now()]);
        self::notifyRequester($request, true);

        return $request;
    }

    public static function reject(WholesalePriceRequest $request, User $approver, ?string $pin, ?string $note = null): WholesalePriceRequest
    {
        self::assertPin($approver, $pin);

        if ($request->status !== 'pendiente') {
            throw ValidationException::withMessages(['pin' => 'Esta solicitud ya fue resuelta.']);
        }

        $request->update([
            'status' => 'rechazada',
            'decided_by' => $approver->id,
            'decided_at' => now(),
            'note' => trim(($request->note ? $request->note . ' | ' : '') . 'Rechazo: ' . ($note ?: 'sin motivo')),
        ]);
        self::notifyRequester($request, false);

        return $request;
    }

    /** El administrador está en la tienda y aprueba en el momento con su PIN. */
    public static function approveOnSpot(?int $customerId, ?int $branchId, array $items, User $requester, User $approver, ?string $pin): WholesalePriceRequest
    {
        $customer = self::assertCanRequest($customerId, $items);
        self::assertPin($approver, $pin);

        return WholesalePriceRequest::create([
            'branch_id' => $branchId,
            'customer_id' => $customer->id,
            'requested_by' => $requester->id,
            'decided_by' => $approver->id,
            'decided_at' => now(),
            'status' => 'aprobada',
            'items' => self::snapshot($items),
            'note' => 'Aprobada en el punto de venta',
        ]);
    }

    /** La aprobación se consume con la venta (una aprobación, una venta). */
    public static function markUsed(?int $requestId, Sale $sale): void
    {
        if (!$requestId) {
            return;
        }

        WholesalePriceRequest::whereKey($requestId)
            ->where('status', 'aprobada')
            ->whereNull('sale_id')
            ->update(['status' => 'usada', 'sale_id' => $sale->id]);
    }

    private static function assertPin(User $approver, ?string $pin): void
    {
        if (!$approver->can('authorize_wholesale_price')) {
            throw ValidationException::withMessages(['pin' => 'Este usuario no puede aprobar precios mayoristas.']);
        }
        if (blank($approver->approval_pin)) {
            throw ValidationException::withMessages(['pin' => "{$approver->name} todavía no tiene un PIN de aprobación. Configúralo en Usuarios."]);
        }
        if (!$approver->checkApprovalPin($pin)) {
            throw ValidationException::withMessages(['pin' => 'PIN incorrecto.']);
        }
    }

    private static function notifyRequester(WholesalePriceRequest $request, bool $approved): void
    {
        $requester = $request->requester;
        if (!$requester) {
            return;
        }

        Notification::make()
            ->title($approved ? 'Precio mayorista aprobado' : 'Precio mayorista rechazado')
            ->body(($request->customer?->name ?? 'Cliente') . ': ' . $request->items_summary
                . ($approved ? '. Ya puedes completar la venta.' : '.'))
            ->{$approved ? 'success' : 'danger'}()
            ->sendToDatabase($requester);
    }

    private static function snapshot(array $items): array
    {
        $variants = ProductVariant::with('product')
            ->whereIn('id', collect($items)->pluck('product_variant_id')->filter())
            ->get()->keyBy('id');

        return collect($items)
            ->filter(fn ($i) => !empty($i['product_variant_id']))
            ->map(fn ($i) => [
                'product_variant_id' => (int) $i['product_variant_id'],
                'quantity' => (int) ($i['quantity'] ?? 1),
                'name' => $variants->get((int) $i['product_variant_id'])?->display_name ?? 'Prenda',
            ])
            ->values()->all();
    }
}
