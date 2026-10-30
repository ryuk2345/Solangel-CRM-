<?php

namespace App\Models;

class AuditSale extends Sale
{
    protected $table = 'sales';

    /**
     * Relación explícita con la FK correcta 'sale_id'.
     * Sin esto, Eloquent buscaría 'audit_sale_id' y lanzaría un error SQL.
     */
    public function items()
    {
        return $this->hasMany(SaleItem::class, 'sale_id');
    }

    public function payments()
    {
        return $this->hasMany(SalePayment::class, 'sale_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function user()
    {
        return $this->belongsTo(\App\Models\User::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}
