<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DespatchItem extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function despatch()
    {
        return $this->belongsTo(Despatch::class);
    }

    public function productVariant()
    {
        return $this->belongsTo(ProductVariant::class);
    }
}
