<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SaleItem extends Model
{
    use HasFactory;
protected $fillable = [
        'sale_id', 'product_variant_id', 'product_name', 
        'quantity', 'price', 'igv','subtotal', 'total'
    ];

    public function productVariant()
    {
        return $this->belongsTo(ProductVariant::class);
    }
}