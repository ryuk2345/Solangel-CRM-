<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Solicitud del vendedor para cobrar precio mayorista a un cliente mayorista que lleva
 * menos prendas que el mínimo. Solo un administrador la aprueba, con su PIN (decisión 6).
 */
class WholesalePriceRequest extends Model
{
    protected $guarded = [];

    protected $casts = [
        'items' => 'array',
        'decided_at' => 'datetime',
    ];

    public function branch() { return $this->belongsTo(Branch::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function requester() { return $this->belongsTo(User::class, 'requested_by'); }
    public function decider() { return $this->belongsTo(User::class, 'decided_by'); }
    public function sale() { return $this->belongsTo(Sale::class); }

    /** Aprobada, sin usar y del día: se puede aplicar a una venta de ese cliente y tienda. */
    public function isUsableFor(?int $customerId, ?int $branchId): bool
    {
        return $this->status === 'aprobada'
            && $this->sale_id === null
            && (int) $this->customer_id === (int) $customerId
            && (int) $this->branch_id === (int) $branchId
            && $this->decided_at?->isSameDay(now());
    }

    public function getItemsSummaryAttribute(): string
    {
        return collect($this->items ?? [])
            ->map(fn ($i) => ($i['quantity'] ?? 1) . ' × ' . ($i['name'] ?? 'prenda'))
            ->implode(', ');
    }
}
