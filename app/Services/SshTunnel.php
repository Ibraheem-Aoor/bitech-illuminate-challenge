<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use PDO;
use PDOException;
use RuntimeException;
use Symfony\Component\Process\Process;

class SshTunnel
{
    private const API_BASE        = 'https://illuminate.bitech.com.sa/api';
    private const TUNNEL_PORT     = 15432;
    private const TUNNEL_WAIT_SEC = 2;

    private ?Process $process = null;
    private ?string  $keyPath = null;

    public static function connect(string $token, callable $callback): mixed
    {
        $tunnel = new self();

        $credentials = $tunnel->fetchCredentials($token);
        ['ssh' => $ssh, 'database' => $db] = $credentials;

        $tunnel->keyPath = $tunnel->writePrivateKey($ssh['private_key']);

        try {
            $tunnel->open($ssh, $db);
            $pdo = $tunnel->pdo($db);
            return $callback($pdo, $db);
        } finally {
            $tunnel->close();
        }
    }

    private function fetchCredentials(string $token): array
    {
        $response = Http::withToken($token)
            ->withoutVerifying()
            ->get(self::API_BASE . '/challenge/ssh-key');

        if ($response->failed()) {
            throw new RuntimeException('Failed to fetch credentials: HTTP ' . $response->status());
        }

        return $response->json();
    }

    private function writePrivateKey(string $privateKey): string
    {
        $path = sys_get_temp_dir() . '/illuminate_challenge_' . getmypid() . '.pem';
        file_put_contents($path, $privateKey);
        chmod($path, 0600);
        return $path;
    }

    private function open(array $ssh, array $db): void
    {
        $this->process = new Process([
            'ssh',
            '-i', $this->keyPath,
            '-o', 'StrictHostKeyChecking=no',
            '-o', 'BatchMode=yes',
            '-o', 'ExitOnForwardFailure=yes',
            '-L', self::TUNNEL_PORT . ':localhost:' . $db['port'],
            '-p', (string) $ssh['port'],
            '-N',
            $ssh['username'] . '@' . $ssh['host'],
        ]);

        $this->process->start();
        sleep(self::TUNNEL_WAIT_SEC);

        if (! $this->process->isRunning()) {
            throw new RuntimeException('SSH tunnel failed: ' . $this->process->getErrorOutput());
        }
    }

    private function pdo(array $db): PDO
    {
        try {
            return new PDO(
                sprintf('pgsql:host=127.0.0.1;port=%d;dbname=%s', self::TUNNEL_PORT, $db['name']),
                $db['username'],
                $db['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage());
        }
    }

    private function close(): void
    {
        $this->process?->stop();

        if ($this->keyPath && file_exists($this->keyPath)) {
            @unlink($this->keyPath);
        }
    }
}
