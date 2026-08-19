<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Material extends Model
{
    protected $fillable = ['name', 'code', 'purity', 'description', 'is_active'];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
