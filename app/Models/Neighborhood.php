<?php

declare(strict_types=1);

namespace App\Models;

use App\Relations\DonutRelation;
use Illuminate\Database\Eloquent\Model;

class Neighborhood extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'id',
        'name',
        'centroid_lat',
        'centroid_lng',
        'boundary',
        'properties',
    ];

    protected $casts = [
        'centroid_lat' => 'float',
        'centroid_lng' => 'float',
        'boundary'     => 'array',
        'properties'   => 'array',
    ];

    public function incidents(): DonutRelation
    {
        return new DonutRelation($this, innerKm: 0.5, outerKm: 2.0);
    }
}
