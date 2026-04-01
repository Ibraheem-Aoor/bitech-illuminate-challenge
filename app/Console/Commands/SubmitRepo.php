<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SubmitRepo extends Command
{
    protected $signature = 'challenge:submit-repo
        {repo_url : Public GitHub repository URL}
        {cv : Absolute path to your CV PDF file}
        {--token= : Bitech challenge bearer token (falls back to BITECH_TOKEN env)}';

    protected $description = 'Final step: Submit your GitHub repo and CV to Bi-Tech';

    public function handle(): int
    {
        $token   = $this->option('token') ?? env('BITECH_TOKEN');
        $repoUrl = $this->argument('repo_url');
        $cvPath  = $this->argument('cv');

        if (! $token) {
            $this->error('No token provided. Use --token=<token> or set BITECH_TOKEN in .env');
            return self::FAILURE;
        }

        if (! file_exists($cvPath)) {
            $this->error("CV file not found: {$cvPath}");
            return self::FAILURE;
        }

        $this->info("Submitting repo: {$repoUrl}");
        $this->info("CV: {$cvPath}");

        $response = Http::withToken($token)
            ->withoutVerifying()
            ->attach('cv', file_get_contents($cvPath), basename($cvPath))
            ->post('https://illuminate.bitech.com.sa/api/challenge/submit-repo', [
                'repo_url' => $repoUrl,
            ]);

        if ($response->failed()) {
            $this->error('Submission failed: HTTP ' . $response->status());
            $this->line($response->body());
            return self::FAILURE;
        }

        $this->info('Submitted successfully!');
        $this->line($response->body());

        return self::SUCCESS;
    }
}
