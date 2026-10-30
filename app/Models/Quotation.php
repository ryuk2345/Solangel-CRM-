<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Quotation extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function booted()
    {
        static::creating(function ($quotation) {
            // Generar correlativo automático seguro: COT-000001
            $ultimoId = static::max('id') ?? 0;
            $nuevoId = $ultimoId + 1;
            $quotation->correlative = 'COT-' . str_pad($nuevoId, 6, '0', STR_PAD_LEFT);
        });
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function items()
    {
        return $this->hasMany(QuotationItem::class);
    }

    public function sale()
    {
        return $this->hasOne(Sale::class);
    }
}
