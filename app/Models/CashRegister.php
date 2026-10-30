<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashRegister extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'branch_id', 'status', 'opened_at', 'closed_at',
        'initial_amount', 'cash_sales', 'cash_incomes', 'cash_expenses',
        'expected_amount', 'declared_amount', 'difference', 'notes'
    ];

    // Relaciones
    public function user() { return $this->belongsTo(User::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function movements() { return $this->hasMany(CashRegisterMovement::class); }

    /**
     * Obtener la caja abierta adecuada para una venta realizada por un super_admin en una sucursal.
     * 1. Prioriza la caja de un cajero regular (no super_admin).
     * 2. Usa la caja de un super_admin solo si es la única abierta hoy en la sucursal.
     */
    public static function getActiveForSuperAdmin(int $branchId): ?self
    {
        $hoyLima = now()->setTimezone('America/Lima')->startOfDay();

        // 1. Buscar caja abierta hoy de un cajero (no super_admin)
        $cajaCajero = static::where('branch_id', $branchId)
            ->where('status', 'abierta')
            ->where('opened_at', '>=', $hoyLima)
            ->whereDoesntHave('user.roles', function ($q) {
                $q->where('name', 'super_admin');
            })
            ->latest('opened_at')
            ->first();

        if ($cajaCajero) {
            return $cajaCajero;
        }

        // 2. Si no hay caja de cajero, usar la del super_admin solo si es la única abierta
        return static::where('branch_id', $branchId)
            ->where('status', 'abierta')
            ->where('opened_at', '>=', $hoyLima)
            ->latest('opened_at')
            ->first();
    }
}