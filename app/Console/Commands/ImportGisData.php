<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Incident;
use App\Models\Neighborhood;
use App\Services\SshTunnel;
use Illuminate\Console\Command;
use PDO;

class ImportGisData extends Command
{
    protected $signature = 'challenge:import
        {--token= : Bitech challenge bearer token (falls back to BITECH_TOKEN env)}
        {--fresh : Truncate local tables before importing}';

    protected $description = 'Stage 3: Import neighborhoods and incidents from remote PostgreSQL into local SQLite';

    public function handle(): int
    {
        $token = $this->option('token') ?? env('BITECH_TOKEN');

        if (! $token) {
            $this->error('No token provided.');
            return self::FAILURE;
        }

        if ($this->option('fresh')) {
            // Clear existing local data so re-runs start from a clean state
            Incident::truncate();
            Neighborhood::truncate();
            $this->info('Local tables cleared.');
        }

        $this->info('Opening SSH tunnel...');

        SshTunnel::connect($token, function (PDO $pdo) {
            $this->importNeighborhoods($pdo);
            $this->importIncidents($pdo);
        });

        return self::SUCCESS;
    }

    private function importNeighborhoods(PDO $pdo): void
    {
        $rows = $pdo->query('SELECT * FROM gis_data.neighborhoods')->fetchAll(PDO::FETCH_ASSOC);

        $this->info("Importing {$this->count($rows)} neighborhoods...");

        foreach ($rows as $row) {
            // Parse the PostgreSQL polygon into an array of [lat, lng] vertices,
            // then compute the centroid so we have a single reference point
            // for distance calculations in DonutRelation.
            $points = $this->parsePolygon($row['boundary']);
            [$lat, $lng] = $this->centroid($points);

            Neighborhood::updateOrCreate(['id' => $row['id']], [
                'name'         => $row['name'],
                'centroid_lat' => $lat,
                'centroid_lng' => $lng,
                'boundary'     => $points,
                'properties'   => json_decode($row['properties'], true),
            ]);
        }

        $this->info('Neighborhoods imported.');
    }

    private function importIncidents(PDO $pdo): void
    {
        $rows = $pdo->query('SELECT * FROM gis_data.incidents')->fetchAll(PDO::FETCH_ASSOC);

        $this->info("Importing {$this->count($rows)} incidents...");

        foreach ($rows as $row) {
            // Parse the PostgreSQL point "(lat,lng)" into separate float columns
            // so SQLite can store and compare them without geospatial extensions.
            [$lat, $lng] = $this->parsePoint($row['location']);

            // The flag code for each incident is nested inside the JSONB metadata
            // under incident.code — extract it at import time for easy querying.
            $metadata = json_decode($row['metadata'], true);

            Incident::updateOrCreate(['id' => $row['id']], [
                'lat'         => $lat,
                'lng'         => $lng,
                'code'        => $metadata['incident']['code'],
                'occurred_at' => $row['occurred_at'],
                'metadata'    => $metadata,
            ]);
        }

        $this->info('Incidents imported.');
    }

    /**
     * Parse a PostgreSQL polygon string into an array of [lat, lng] pairs.
     * Input:  "((33.32,44.34),(33.325,44.365),(33.34,44.365),(33.34,44.34))"
     * Output: [[33.32, 44.34], [33.325, 44.365], ...]
     */
    private function parsePolygon(string $polygon): array
    {
        preg_match_all('/\(([^()]+)\)/', $polygon, $matches);

        return array_map(function (string $pair) {
            [$lat, $lng] = explode(',', $pair);
            return [(float) $lat, (float) $lng];
        }, $matches[1]);
    }

    /**
     * Parse a PostgreSQL point string into [lat, lng].
     * Input:  "(33.334851,44.3525)"
     * Output: [33.334851, 44.3525]
     */
    private function parsePoint(string $point): array
    {
        [$lat, $lng] = explode(',', trim($point, '()'));
        return [(float) $lat, (float) $lng];
    }

    /**
     * Calculate the centroid of a polygon as the arithmetic mean of its vertices.
     * This is a simple average and works well for small polygons like neighborhoods.
     */
    private function centroid(array $points): array
    {
        $count = count($points);
        $lat   = array_sum(array_column($points, 0)) / $count;
        $lng   = array_sum(array_column($points, 1)) / $count;
        return [$lat, $lng];
    }

    private function count(array $rows): int
    {
        return count($rows);
    }
}
