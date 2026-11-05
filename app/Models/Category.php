<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;

    // ESTA ES LA LÍNEA MÁGICA QUE FALTA:
    protected $guarded = []; 

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}