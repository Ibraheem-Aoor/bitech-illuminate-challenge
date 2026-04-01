<?php

declare(strict_types=1);

namespace App\Relations;

use App\Models\Incident;
use App\Models\Neighborhood;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * A custom Eloquent relation that returns incidents within a donut-shaped
 * area (annulus) around a neighborhood's centroid.
 *
 * Usage:
 *   $neighborhood->incidents()           // DonutRelation instance
 *   $neighborhood->incidents()->get()    // Collection<Incident>, ordered by distance
 */
class DonutRelation extends Relation
{
    public function __construct(
        Neighborhood $parent,
        private readonly float $innerKm,
        private readonly float $outerKm,
    ) {
        parent::__construct(Incident::query(), $parent);
    }

    /** No SQL constraints — filtering is done in PHP via Haversine. */
    public function addConstraints(): void {}

    /** Load all incidents; per-neighborhood filtering happens in match(). */
    public function addEagerConstraints(array $models): void {}

    public function initRelation(array $models, $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->related->newCollection());
        }

        return $models;
    }

    public function match(array $models, Collection $results, $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->filterAndSort($results, $model));
        }

        return $models;
    }

    public function getResults(): Collection
    {
        return $this->filterAndSort($this->query->get(), $this->parent);
    }

    // -------------------------------------------------------------------------

    private function filterAndSort(Collection $incidents, Model $neighborhood): Collection
    {
        $lat = $neighborhood->centroid_lat;
        $lng = $neighborhood->centroid_lng;

        return $incidents
            ->filter(fn (Incident $i) => $this->inDonut($lat, $lng, $i->lat, $i->lng))
            ->sortBy(fn (Incident $i) => $this->haversine($lat, $lng, $i->lat, $i->lng))
            ->values();
    }

    private function inDonut(float $lat1, float $lng1, float $lat2, float $lng2): bool
    {
        $d = $this->haversine($lat1, $lng1, $lat2, $lng2);

        return $d >= $this->innerKm && $d <= $this->outerKm;
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r    = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a    = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * asin(sqrt($a));
    }
}
