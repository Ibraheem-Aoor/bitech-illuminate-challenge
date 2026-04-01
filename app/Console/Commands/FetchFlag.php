<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SshTunnel;
use Illuminate\Console\Command;
use PDO;

class FetchFlag extends Command
{
    protected $signature = 'challenge:fetch-flag
        {--token= : Bitech challenge bearer token (falls back to BITECH_TOKEN env)}';

    protected $description = 'Stage 2: SSH tunnel into the remote server and retrieve the flag from PostgreSQL';

    public function handle(): int
    {
        $token = $this->option('token') ?? env('BITECH_TOKEN');

        if (! $token) {
            $this->error('No token provided. Use --token=<token> or set BITECH_TOKEN in .env');
            return self::FAILURE;
        }

        $this->info('Opening tunnel and connecting to database...');

        $flag = SshTunnel::connect($token, function (PDO $pdo) {
            $tables = $pdo
                ->query("SELECT schemaname || '.' || tablename FROM pg_catalog.pg_tables WHERE schemaname NOT IN ('pg_catalog','information_schema') ORDER BY tablename")
                ->fetchAll(PDO::FETCH_COLUMN);

            foreach ($tables as $table) {
                $rows = $pdo->query("SELECT * FROM {$table} LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);

                foreach ($rows as $row) {
                    foreach ($row as $column => $value) {
                        if (is_string($value) && str_contains($value, 'ILLUMINATE{')) {
                            return ['table' => $table, 'column' => $column, 'flag' => $value];
                        }
                    }
                }
            }

            return null;
        });

        if ($flag === null) {
            $this->warn('No ILLUMINATE{} flag found in any table.');
            return self::FAILURE;
        }

        $this->info("Flag found in [{$flag['table']}.{$flag['column']}]:");
        $this->line($flag['flag']);

        return self::SUCCESS;
    }
}
