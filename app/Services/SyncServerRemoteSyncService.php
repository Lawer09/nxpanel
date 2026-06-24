<?php

namespace App\Services;

use App\Models\SyncServer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

class SyncServerRemoteSyncService
{
    public const ENDPOINT_ACCOUNT_META = 'account-meta';
    public const ENDPOINT_APPS = 'apps';
    public const ENDPOINT_REVENUE = 'revenue';

    /**
     * Trigger a remote sync endpoint on the configured sync server.
     *
     * @return array{url: string, httpStatus: int, body: mixed}
     *
     * @throws ConnectionException
     */
    public function trigger(SyncServer $server, string $endpoint, array $query = []): array
    {
        $this->ensureConfigured($server);

        $url = $this->buildUrl($server, $endpoint, $query, false);
        $response = Http::timeout(30)->post($url);
        $body = $response->json() ?? $response->body();

        return [
            'url' => $this->buildUrl($server, $endpoint, $query, true),
            'httpStatus' => $response->status(),
            'code' => is_array($body) ? ($body['code'] ?? null) : null,
            'msg' => is_array($body) ? ($body['msg'] ?? null) : null,
            'data' => is_array($body) ? ($body['data'] ?? null) : null,
            'body' => $body,
        ];
    }

    /**
     * Ensure the sync server can be called by remote sync endpoints.
     */
    private function ensureConfigured(SyncServer $server): void
    {
        if (empty($server->host_ip)) {
            throw new InvalidArgumentException('sync server host_ip is not configured');
        }

        if (empty($server->secret_key)) {
            throw new InvalidArgumentException('sync server secret_key is not configured');
        }
    }

    /**
     * Build the remote URL and optionally mask the API key for response payloads.
     */
    private function buildUrl(SyncServer $server, string $endpoint, array $query, bool $maskKey): string
    {
        $port = $server->port ?: 8080;
        if ($maskKey) {
            $query['key'] = '***';
            $queryString = http_build_query($query);
            $queryString = str_replace('key=%2A%2A%2A', 'key=***', $queryString);

            return "http://{$server->host_ip}:{$port}/api/sync/{$endpoint}?{$queryString}";
        }

        $query['key'] = $server->secret_key;

        return "http://{$server->host_ip}:{$port}/api/sync/{$endpoint}?" . http_build_query($query);
    }
}
