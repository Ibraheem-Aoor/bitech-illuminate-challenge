<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use PDO;
use PDOException;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Opens an SSH port-forward tunnel to the remote server and provides
 * a PDO connection to the PostgreSQL instance running behind it.
 *
 * The remote database is only reachable via SSH (host: localhost from
 * the server's perspective), so we forward a local port to it:
 *   127.0.0.1:TUNNEL_PORT  →  SSH server  →  localhost:5432
 *
 * Usage:
 *   SshTunnel::connect($token, function (PDO $pdo) {
 *       // interact with the remote database
 *   });
 *
 * The tunnel and temp key file are always cleaned up via try/finally,
 * even if the callback throws.
 */
class SshTunnel
{
    private const API_BASE        = 'https://illuminate.bitech.com.sa/api';
    private const TUNNEL_PORT     = 15432; // local port we forward from
    private const TUNNEL_WAIT_SEC = 2;     // seconds to wait for tunnel to establish

    private ?Process $process = null;
    private ?string  $keyPath = null;

    /**
     * Fetch credentials, open the tunnel, run the callback with a PDO connection,
     * then tear everything down — regardless of whether the callback succeeds.
     */
    public static function connect(string $token, callable $callback): mixed
    {
        $tunnel = new self();

        $credentials = $tunnel->fetchCredentials($token);
        ['ssh' => $ssh, 'database' => $db] = $credentials;

        // Write the private key to a temp file; SSH requires a file path, not a string
        $tunnel->keyPath = $tunnel->writePrivateKey($ssh['private_key']);

        try {
            $tunnel->open($ssh, $db);
            $pdo = $tunnel->pdo($db);
            return $callback($pdo, $db);
        } finally {
            // Always stop the tunnel process and delete the temp key file
            $tunnel->close();
        }
    }

    /**
     * Fetch the SSH private key and database credentials from the challenge API.
     */
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

    /**
     * Write the private key to a temp file with strict permissions (0600),
     * as SSH will refuse to use a key file that is world-readable.
     */
    private function writePrivateKey(string $privateKey): string
    {
        $path = sys_get_temp_dir() . '/illuminate_challenge_' . getmypid() . '.pem';
        file_put_contents($path, $privateKey);
        chmod($path, 0600);
        return $path;
    }

    /**
     * Start the SSH tunnel as a background process using Symfony Process.
     * The -L flag forwards TUNNEL_PORT on localhost to port 5432 on the remote host.
     * We wait briefly then confirm the process is still running.
     */
    private function open(array $ssh, array $db): void
    {
        $this->process = new Process([
            'ssh',
            '-i', $this->keyPath,
            '-o', 'StrictHostKeyChecking=no',  // skip host key prompt in non-interactive use
            '-o', 'BatchMode=yes',              // never prompt for passwords
            '-o', 'ExitOnForwardFailure=yes',   // fail fast if port-forward can't be set up
            '-L', self::TUNNEL_PORT . ':localhost:' . $db['port'],
            '-p', (string) $ssh['port'],
            '-N',                               // do not execute a remote command, just forward
            $ssh['username'] . '@' . $ssh['host'],
        ]);

        $this->process->start();
        sleep(self::TUNNEL_WAIT_SEC);

        if (! $this->process->isRunning()) {
            throw new RuntimeException('SSH tunnel failed: ' . $this->process->getErrorOutput());
        }
    }

    /**
     * Connect to PostgreSQL through the tunnel using PDO.
     * The host is 127.0.0.1 because the tunnel is forwarded to our local port.
     */
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

    /**
     * Stop the SSH tunnel process and remove the temp key file.
     */
    private function close(): void
    {
        $this->process?->stop();

        if ($this->keyPath && file_exists($this->keyPath)) {
            @unlink($this->keyPath);
        }
    }
}
