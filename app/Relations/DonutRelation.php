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
 * A "donut" is defined by two concentric circles sharing the same center:
 *   - Incidents closer than $innerKm are excluded (the hole).
 *   - Incidents farther than $outerKm are excluded (outside the ring).
 *
 * This class extends Laravel's base Relation — the same foundation used
 * by HasOne, BelongsToMany, etc. — rather than using any built-in type,
 * because no built-in relation models distance-based filtering.
 *
 * Usage:
 *   $neighborhood->incidents()        // returns this DonutRelation
 *   $neighborhood->incidents()->get() // Collection<Incident>, ordered by distance
 */
class DonutRelation extends Relation
{
    public function __construct(
        Neighborhood $parent,
        private readonly float $innerKm,
        private readonly float $outerKm,
    ) {
        // Pass an Incident query builder and the parent Neighborhood to Laravel's
        // base Relation. This makes $this->query and $this->parent available.
        parent::__construct(Incident::query(), $parent);
    }

    /**
     * Called by Laravel when the relation is accessed directly (not eager loaded).
     * We apply no SQL constraints here because distance filtering cannot be
     * expressed in SQLite without geospatial extensions. We filter in PHP instead.
     */
    public function addConstraints(): void {}

    /**
     * Called during eager loading to restrict the query to the given parent models.
     * We load all incidents and let match() handle per-neighborhood filtering,
     * which avoids N+1 queries while keeping the logic portable.
     */
    public function addEagerConstraints(array $models): void {}

    /**
     * Initialize the relation on a set of parent models before results are matched.
     * Sets an empty collection as the default so models without matches return []
     * rather than null.
     */
    public function initRelation(array $models, $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->related->newCollection());
        }

        return $models;
    }

    /**
     * Match eager-loaded incidents back to their parent neighborhoods.
     * Each neighborhood gets its own filtered + sorted subset based on
     * its individual centroid coordinates.
     */
    public function match(array $models, Collection $results, $relation): array
    {
        foreach ($models as $model) {
            $model->setRelation($relation, $this->filterAndSort($results, $model));
        }

        return $models;
    }

    /**
     * Return the incidents for the current parent neighborhood.
     * Loads all incidents from the DB, then filters and sorts them in PHP.
     */
    public function getResults(): Collection
    {
        return $this->filterAndSort($this->query->get(), $this->parent);
    }

    // -------------------------------------------------------------------------

    /**
     * Filter incidents to those within the donut ring around the neighborhood's
     * centroid, then sort them by ascending distance.
     */
    private function filterAndSort(Collection $incidents, Model $neighborhood): Collection
    {
        $lat = $neighborhood->centroid_lat;
        $lng = $neighborhood->centroid_lng;

        return $incidents
            ->filter(fn(Incident $i) => $this->inDonut($lat, $lng, $i->lat, $i->lng))
            ->sortBy(fn(Incident $i) => $this->haversine($lat, $lng, $i->lat, $i->lng))
            ->values();
    }

    /**
     * Check whether a point (lat2, lng2) falls within the donut ring
     * centered at (lat1, lng1), i.e. between innerKm and outerKm.
     */
    private function inDonut(float $lat1, float $lng1, float $lat2, float $lng2): bool
    {
        $d = $this->haversine($lat1, $lng1, $lat2, $lng2);

        return $d >= $this->innerKm && $d <= $this->outerKm;
    }

    /**
     * Calculate the great-circle distance in kilometres between two coordinates
     * using the Haversine formula. This accounts for the Earth's curvature
     * and is accurate enough for distances up to a few hundred kilometres.
     *
     * @see https://en.wikipedia.org/wiki/Haversine_formula
     */
    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r    = 6371; // Earth's mean radius in kilometres
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a    = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $r * 2 * asin(sqrt($a));
    }
}
