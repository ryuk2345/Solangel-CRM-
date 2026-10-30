<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CashRegisterMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'cash_register_id', 'user_id', 'type', 'amount', 'description'
    ];

    public function cashRegister() { return $this->belongsTo(CashRegister::class); }
    public function user() { return $this->belongsTo(User::class); }
}