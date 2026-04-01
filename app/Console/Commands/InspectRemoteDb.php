<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SshTunnel;
use Illuminate\Console\Command;
use PDO;

class InspectRemoteDb extends Command
{
    protected $signature = 'challenge:inspect
        {--token= : Bitech challenge bearer token (falls back to BITECH_TOKEN env)}';

    protected $description = 'Inspect remote PostgreSQL schema and sample data';

    public function handle(): int
    {
        $token = $this->option('token') ?? env('BITECH_TOKEN');

        if (! $token) {
            $this->error('No token provided.');
            return self::FAILURE;
        }

        $this->info('Opening tunnel...');

        SshTunnel::connect($token, function (PDO $pdo) {
            // Column definitions for each table
            $tables = $pdo
                ->query("SELECT schemaname || '.' || tablename FROM pg_catalog.pg_tables WHERE schemaname NOT IN ('pg_catalog','information_schema') ORDER BY tablename")
                ->fetchAll(PDO::FETCH_COLUMN);

            foreach ($tables as $table) {
                $this->line("\n=== {$table} ===");

                $cols = $pdo->query("
                    SELECT column_name, data_type, character_maximum_length
                    FROM information_schema.columns
                    WHERE table_schema || '.' || table_name = '{$table}'
                    ORDER BY ordinal_position
                ")->fetchAll(PDO::FETCH_ASSOC);

                foreach ($cols as $col) {
                    $this->line("  {$col['column_name']}  ({$col['data_type']})");
                }

                $this->line('  -- sample row --');
                $row = $pdo->query("SELECT * FROM {$table} LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                $this->line('  ' . json_encode($row));
            }
        });

        return self::SUCCESS;
    }
}
