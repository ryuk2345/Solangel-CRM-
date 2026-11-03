<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleReturnItem extends Model
{
    protected $guarded = [];

    public function saleReturn() { return $this->belongsTo(SaleReturn::class); }
    public function saleItem() { return $this->belongsTo(SaleItem::class); }
    public function productVariant() { return $this->belongsTo(ProductVariant::class); }
}
