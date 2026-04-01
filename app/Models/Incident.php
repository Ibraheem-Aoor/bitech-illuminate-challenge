<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Incident extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'id',
        'lat',
        'lng',
        'code',
        'occurred_at',
        'metadata',
    ];

    protected $casts = [
        'lat'         => 'float',
        'lng'         => 'float',
        'occurred_at' => 'datetime',
        'metadata'    => 'array',
    ];
}
