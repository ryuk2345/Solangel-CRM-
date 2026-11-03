<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Cambio de talla/color o devolución de prendas de una venta (decisión 7).
 * Si la venta fue boleta o factura, genera una nota de crédito parcial (motivo 07: devolución por ítem).
 */
class SaleReturn extends Model
{
    protected $guarded = [];

    protected $casts = [
        'total' => 'decimal:2',
        'refunded_cash' => 'decimal:2',
    ];

    public const REASONS = [
        'Talla incorrecta' => 'Talla incorrecta',
        'Cambio de color' => 'Cambio de color',
        'Cambio de modelo' => 'Cambio de modelo',
        'Prenda con defecto' => 'Prenda con defecto',
        'Otro' => 'Otro',
    ];

    public function sale() { return $this->belongsTo(Sale::class); }
    public function newSale() { return $this->belongsTo(Sale::class, 'new_sale_id'); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function items() { return $this->hasMany(SaleReturnItem::class); }

    public function isElectronic(): bool
    {
        return !str_starts_with((string) $this->sale?->serie, 'NV');
    }

    public function getCreditNoteNumberAttribute(): ?string
    {
        return $this->credit_note_serie
            ? $this->credit_note_serie . '-' . str_pad((string) $this->credit_note_correlative, 8, '0', STR_PAD_LEFT)
            : null;
    }

    /** Saldo a favor todavía no usado en una venta de cambio. */
    public function getAvailableCreditAttribute(): float
    {
        return $this->type === 'cambio' && $this->new_sale_id === null ? (float) $this->total : 0.0;
    }
}
