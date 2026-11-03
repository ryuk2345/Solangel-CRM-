<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];

    public function purchases()
    {
        return $this->hasMany(Purchase::class);
    }
}
