<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;

class DnsToolService
{
    private string $baseUrl;
    private string $apiToken;

    public function __construct()
    {
        $host = config('services.dns_tool.host', '8.221.113.81:8080');
        $this->baseUrl  = 'http://' . $host . '/api/v1';
        $this->apiToken = config('services.dns_tool.token', 'd2d');
    }

    // ----------------------------------------------------------------
    // Internal
    // ----------------------------------------------------------------

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withHeaders([
            'X-API-Token' => $this->apiToken,
            'Accept'      => 'application/json',
        ])->baseUrl($this->baseUrl);
    }

    /**
     * Unwrap the remote response and return its `data` field.
     * Throws a \RuntimeException when remote code != 0.
     */
    private function unwrap(Response $response): mixed
    {
        $body = $response->json();

        if (!$response->successful()) {
            $message = $body['message'] ?? 'remote api error';
            $code    = $body['code']    ?? $response->status();
            throw new \RuntimeException($message, (int) $code);
        }

        if (isset($body['code']) && $body['code'] !== 0) {
            throw new \RuntimeException($body['message'] ?? 'remote api error', (int) $body['code']);
        }

        return $body['data'] ?? null;
    }

    // ----------------------------------------------------------------
    // Domain methods
    // ----------------------------------------------------------------

    public function getAvailableDomains(): array
    {
        $resp = $this->client()->get('/domains/available');
        return $this->unwrap($resp) ?? [];
    }

    public function getAvailableDomainsDetail(): array
    {
        $resp = $this->client()->get('/domains/available/detail');
        return $this->unwrap($resp) ?? [];
    }

    public function getUnavailableDomains(): array
    {
        $resp = $this->client()->get('/domains/unavailable');
        return $this->unwrap($resp) ?? [];
    }

    public function disableDomain(string $domain): array
    {
        $resp = $this->client()->post('/domains/disable', ['domain' => $domain]);
        return $this->unwrap($resp) ?? [];
    }

    public function enableDomain(string $domain): array
    {
        $resp = $this->client()->post('/domains/enable', ['domain' => $domain]);
        return $this->unwrap($resp) ?? [];
    }

    public function syncDomains(): array
    {
        $resp = $this->client()->post('/domains/sync');
        return $this->unwrap($resp) ?? [];
    }

    // ----------------------------------------------------------------
    // Record methods
    // ----------------------------------------------------------------

    public function resolveRecord(string $ipv4, string $subdomain, string $domain, bool $unique = false): array
    {
        $resp = $this->client()->post('/records/resolve', [
            'ipv4'      => $ipv4,
            'subdomain' => $subdomain,
            'domain'    => $domain,
            'unique'    => $unique,
        ]);
        return $this->unwrap($resp) ?? [];
    }

    public function getRecordsByIp(string $ipv4): array
    {
        $resp = $this->client()->get('/records/by-ip', ['ipv4' => $ipv4]);
        return $this->unwrap($resp) ?? [];
    }

    public function unbindRecord(string $ipv4, string $fqdn): array
    {
        $resp = $this->client()->post('/records/unbind', [
            'ipv4' => $ipv4,
            'fqdn' => $fqdn,
        ]);
        return $this->unwrap($resp) ?? [];
    }
}
