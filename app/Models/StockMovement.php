<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Kardex: cada entrada o salida de stock por variante y almacén. */
class StockMovement extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    public const TYPES = [
        'sale' => 'Venta',
        'sale_void' => 'Anulación de venta',
        'purchase' => 'Compra',
        'purchase_void' => 'Compra anulada',
        'adjustment' => 'Ajuste de inventario',
        'transfer_out' => 'Traspaso (salida)',
        'transfer_in' => 'Traspaso (entrada)',
        'transfer_return' => 'Traspaso devuelto',
        'exchange_in' => 'Cambio (devolución)',
        'exchange_out' => 'Cambio (entrega)',
        'credit_note' => 'Nota de crédito',
        'initial' => 'Stock inicial',
    ];

    public function productVariant()
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reference()
    {
        return $this->morphTo();
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? $this->type;
    }
}
