<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Showroom extends Model
{
    protected $fillable = [
        'name', 'city', 'address', 'phone', 'email', 'map_url', 'business_hours', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'business_hours' => 'array',
    ];
}