<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\DnsToolService;
use Illuminate\Http\Request;

class DnsToolController extends Controller
{
    private DnsToolService $service;

    public function __construct(DnsToolService $service)
    {
        $this->service = $service;
    }

    // ----------------------------------------------------------------
    // Domain endpoints
    // ----------------------------------------------------------------

    /**
     * GET /dns/domains/available
     * 获取可用主域名列表
     */
    public function availableDomains()
    {
        try {
            return $this->ok($this->service->getAvailableDomains());
        } catch (\RuntimeException $e) {
            return $this->error([$e->getCode() ?: 500, $e->getMessage()]);
        }
    }

    /**
     * GET /dns/domains/available/detail
     * 获取可用主域名（含解析记录）
     */
    public function availableDomainsDetail()
    {
        try {
            return $this->ok($this->service->getAvailableDomainsDetail());
        } catch (\RuntimeException $e) {
            return $this->error([$e->getCode() ?: 500, $e->getMessage()]);
        }
    }

    /**
     * GET /dns/domains/unavailable
     * 获取不可用主域名列表
     */
    public function unavailableDomains()
    {
        try {
            return $this->ok($this->service->getUnavailableDomains());
        } catch (\RuntimeException $e) {
            return $this->error([$e->getCode() ?: 500, $e->getMessage()]);
        }
    }

    /**
     * POST /dns/domains/disable
     * 禁用主域名
     */
    public function disableDomain(Request $request)
    {
        $data = $request->validate([
            'domain' => 'required|string',
        ]);

        try {
            return $this->ok($this->service->disableDomain($data['domain']));
        } catch (\RuntimeException $e) {
            return $this->error([$e->getCode() ?: 500, $e->getMessage()]);
        }
    }

    /**
     * POST /dns/domains/enable
     * 启用主域名
     */
    public function enableDomain(Request $request)
    {
        $data = $request->validate([
            'domain' => 'required|string',
        ]);

        try {
            return $this->ok($this->service->enableDomain($data['domain']));
        } catch (\RuntimeException $e) {
            return $this->error([$e->getCode() ?: 500, $e->getMessage()]);
        }
    }

    /**
     * POST /dns/domains/sync
     * 同步域名信息
     */
    public function syncDomains()
    {
        try {
            return $this->ok($this->service->syncDomains());
        } catch (\RuntimeException $e) {
            return $this->error([$e->getCode() ?: 500, $e->getMessage()]);
        }
    }

    // ----------------------------------------------------------------
    // Record endpoints
    // ----------------------------------------------------------------

    /**
     * POST /dns/records/resolve
     * IP 解析（绑定域名）
     */
    public function resolveRecord(Request $request)
    {
        $data = $request->validate([
            'ipv4'      => 'required|ip',
            'subdomain' => 'required|string',
            'domain'    => 'required|string',
            'unique'    => 'sometimes|boolean',
        ]);

        try {
            $result = $this->service->resolveRecord(
                $data['ipv4'],
                $data['subdomain'],
                $data['domain'],
                (bool) ($data['unique'] ?? false)
            );
            return $this->ok($result);
        } catch (\RuntimeException $e) {
            return $this->error([$e->getCode() ?: 500, $e->getMessage()]);
        }
    }

    /**
     * GET /dns/records/by-ip?ipv4=1.2.3.4
     * 获取 IP 绑定的域名列表
     */
    public function recordsByIp(Request $request)
    {
        $data = $request->validate([
            'ipv4' => 'required|ip',
        ]);

        try {
            return $this->ok($this->service->getRecordsByIp($data['ipv4']));
        } catch (\RuntimeException $e) {
            return $this->error([$e->getCode() ?: 500, $e->getMessage()]);
        }
    }

    /**
     * POST /dns/records/unbind
     * 解绑域名记录
     */
    public function unbindRecord(Request $request)
    {
        $data = $request->validate([
            'ipv4' => 'required|ip',
            'fqdn' => 'required|string',
        ]);

        try {
            return $this->ok($this->service->unbindRecord($data['ipv4'], $data['fqdn']));
        } catch (\RuntimeException $e) {
            return $this->error([$e->getCode() ?: 500, $e->getMessage()]);
        }
    }
}
