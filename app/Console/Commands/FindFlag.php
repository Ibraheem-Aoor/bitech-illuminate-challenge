<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Incident;
use App\Models\Neighborhood;
use Illuminate\Console\Command;

class FindFlag extends Command
{
    protected $signature = 'challenge:find-flag
        {neighborhood : The neighborhood name (e.g. NB-7A2F)}';

    protected $description = 'Stage 3: Find incidents in the donut, concatenate their codes to reveal the flag';

    public function handle(): int
    {
        $name = $this->argument('neighborhood');

        $neighborhood = Neighborhood::where('name', $name)->first();

        if (! $neighborhood) {
            $this->error("Neighborhood [{$name}] not found. Run challenge:import first.");
            return self::FAILURE;
        }

        $this->info("Centroid: {$neighborhood->centroid_lat}, {$neighborhood->centroid_lng}");

        $incidents = $neighborhood->incidents()->get();

        if ($incidents->isEmpty()) {
            $this->warn('No incidents found in the donut (0.5 km – 2.0 km).');
            return self::FAILURE;
        }

        $this->info("Found {$incidents->count()} incident(s) in donut, ordered by distance:");

        $this->table(
            ['#', 'ID', 'Lat', 'Lng', 'Code'],
            $incidents->map(fn (Incident $i, int $idx) => [
                $idx + 1, $i->id, $i->lat, $i->lng, $i->code,
            ]),
        );

        $flag = $incidents->pluck('code')->implode('');

        $this->newLine();
        $this->info("Flag: {$flag}");

        return self::SUCCESS;
    }
}
