<?php

namespace App\Services;

use App\Models\CashRegister;
use App\Models\CashRegisterMovement;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ventas a crédito para mayoristas (Etapa 8b, decisión 8):
 * solo clientes mayoristas con crédito habilitado, dentro de su límite (el administrador puede excederlo),
 * con fecha de vencimiento según sus días de crédito. Los abonos en efectivo entran a la caja.
 */
class CreditService
{
    public const METHODS = [
        'efectivo' => 'Efectivo',
        'yape' => 'Yape',
        'plin' => 'Plin',
        'tarjeta' => 'Tarjeta/POS',
        'transferencia' => 'Transferencia bancaria',
    ];

    /** Valida que el cliente pueda llevar esta deuda. Devuelve la fecha de vencimiento. */
    public static function assertCanSellOnCredit(?Customer $customer, float $amount, ?User $actor): \Carbon\Carbon
    {
        if (!$customer || !$customer->canBuyOnCredit()) {
            throw ValidationException::withMessages([
                'payment_condition' => 'La venta a crédito es solo para clientes mayoristas con crédito habilitado (se activa en Clientes).',
            ]);
        }

        if ($customer->credit_limit !== null) {
            $after = $customer->outstandingBalance() + $amount;
            if ($after > (float) $customer->credit_limit + 0.001 && !$actor?->can('manage_credit')) {
                throw ValidationException::withMessages([
                    'payment_condition' => 'Supera el límite de crédito del cliente: debe S/ ' . number_format($customer->outstandingBalance(), 2)
                        . ' y su límite es S/ ' . number_format((float) $customer->credit_limit, 2) . '. Solo el administrador puede exceptuarlo.',
                ]);
            }
        }

        return now()->startOfDay()->addDays(max(1, (int) $customer->credit_days));
    }

    /** Registra un abono a una venta a crédito. */
    public static function registerPayment(Sale $sale, string $method, float $amount, ?string $reference = null): CustomerPayment
    {
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'El abono debe ser mayor a 0.']);
        }
        if (!array_key_exists($method, self::METHODS)) {
            throw ValidationException::withMessages(['payment_method' => 'Medio de pago no válido.']);
        }

        return DB::transaction(function () use ($sale, $method, $amount, $reference) {
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if ($amount > (float) $sale->credit_balance + 0.001) {
                throw ValidationException::withMessages(['amount' => 'El abono supera el saldo pendiente (S/ ' . number_format((float) $sale->credit_balance, 2) . ').']);
            }

            $register = null;
            if ($method === 'efectivo') {
                $register = CashRegister::where('user_id', Auth::id())->where('branch_id', $sale->branch_id)
                    ->where('status', 'abierta')->latest('opened_at')->lockForUpdate()->first()
                    ?? (Auth::user()?->seesAllBranches() ? CashRegister::getActiveForSuperAdmin($sale->branch_id) : null);

                if (!$register) {
                    throw ValidationException::withMessages(['payment_method' => 'Para cobrar en efectivo necesitas una caja abierta en la tienda de la venta.']);
                }

                CashRegisterMovement::create([
                    'cash_register_id' => $register->id,
                    'user_id' => Auth::id(),
                    'type' => 'ingreso',
                    'amount' => $amount,
                    'description' => "Abono a crédito {$sale->serie}-{$sale->correlative}",
                ]);
                $register->increment('cash_incomes', $amount);
            }

            $payment = CustomerPayment::create([
                'customer_id' => $sale->customer_id,
                'sale_id' => $sale->id,
                'branch_id' => $sale->branch_id,
                'cash_register_id' => $register?->id,
                'user_id' => Auth::id(),
                'payment_method' => $method,
                'amount' => $amount,
                'reference' => $reference,
            ]);

            $sale->update(['credit_balance' => round((float) $sale->credit_balance - $amount, 2)]);

            return $payment;
        });
    }

    public static function isOverdue(Sale $sale): bool
    {
        return (float) $sale->credit_balance > 0 && $sale->due_date && now()->startOfDay()->gt($sale->due_date);
    }
}
