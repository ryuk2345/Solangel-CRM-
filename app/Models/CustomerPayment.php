<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Abono de un cliente a una venta a crédito. */
class CustomerPayment extends Model
{
    protected $guarded = [];

    public function customer() { return $this->belongsTo(Customer::class); }
    public function sale() { return $this->belongsTo(Sale::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function cashRegister() { return $this->belongsTo(CashRegister::class); }
}
