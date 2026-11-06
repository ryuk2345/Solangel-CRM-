<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Compra a proveedor: al registrarse, sus prendas entran al almacén por el kardex. */
class Purchase extends Model
{
    protected $guarded = [];

    protected $casts = [
        'issue_date' => 'date',
        'voided_at' => 'datetime',
        'total' => 'decimal:2',
    ];

    public const DOCUMENT_TYPES = [
        'factura' => 'Factura',
        'boleta' => 'Boleta',
        'guia' => 'Guía de remisión',
        'sin_comprobante' => 'Sin comprobante',
    ];

    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function voider() { return $this->belongsTo(User::class, 'voided_by'); }
    public function items() { return $this->hasMany(PurchaseItem::class); }

    public function getUnitsAttribute(): int
    {
        return (int) $this->items()->sum('quantity');
    }

    public function getReferenceLabelAttribute(): string
    {
        return 'Compra #' . $this->id . ($this->document_number ? " ({$this->document_number})" : '');
    }
}
