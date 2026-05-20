<?php

namespace App\Http\Controllers\V3\Admin;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DnsDomainIndexRequest;
use App\Http\Requests\Admin\DnsDomainSyncRequest;
use App\Http\Requests\Admin\DnsDomainUpdateMetaRequest;
use App\Http\Requests\Admin\DnsIpBindingIndexRequest;
use App\Http\Requests\Admin\DnsIpBindingUpdateMetaRequest;
use App\Http\Requests\Admin\DnsProviderAccountIndexRequest;
use App\Http\Requests\Admin\DnsProviderAccountStoreRequest;
use App\Http\Requests\Admin\DnsProviderAccountUpdateRequest;
use App\Http\Requests\Admin\DnsProviderIndexRequest;
use App\Http\Requests\Admin\DnsProviderStoreRequest;
use App\Http\Requests\Admin\DnsProviderUpdateRequest;
use App\Http\Requests\Admin\DnsRecordResolveRequest;
use App\Http\Requests\Admin\DnsRecordsByIpRequest;
use App\Http\Requests\Admin\DnsRecordUnbindRequest;
use App\Http\Requests\Admin\IdRequest;
use App\Http\Resources\CamelizeResource;
use App\Services\Dns\DnsAdminService;
use App\Services\DnsToolService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class DnsToolController extends Controller
{
    public function __construct(
        private DnsAdminService $adminService,
        private DnsToolService $dnsToolService
    ) {}

    /**
     * DNS Provider 列表。
     */
    public function providers(DnsProviderIndexRequest $request): JsonResponse
    {
        try {
            $result = $this->adminService->providerIndex($request->validated());

            return $this->ok([
                'page' => $result['page'],
                'pageSize' => $result['pageSize'],
                'total' => $result['total'],
                'data' => CamelizeResource::collection($result['data']),
            ]);
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('DnsTool providers error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * DNS Provider 详情。
     */
    public function providerDetail(IdRequest $request): JsonResponse
    {
        try {
            $provider = $this->adminService->providerDetail((int) $request->validated()['id']);
            return $this->ok(CamelizeResource::make($provider));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('DnsTool providerDetail error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 新增 DNS Provider。
     */
    public function createProvider(DnsProviderStoreRequest $request): JsonResponse
    {
        try {
            $provider = $this->adminService->providerStore($request->validated());
            return $this->ok(CamelizeResource::make($provider));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('DnsTool createProvider error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 更新 DNS Provider。
     */
    public function updateProvider(DnsProviderUpdateRequest $request): JsonResponse
    {
        try {
            $provider = $this->adminService->providerUpdate($request->validated());
            return $this->ok(CamelizeResource::make($provider));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('DnsTool updateProvider error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * DNS Provider 账号列表。
     */
    public function providerAccounts(DnsProviderAccountIndexRequest $request): JsonResponse
    {
        try {
            $result = $this->adminService->providerAccountIndex($request->validated());

            return $this->ok([
                'page' => $result['page'],
                'pageSize' => $result['pageSize'],
                'total' => $result['total'],
                'data' => CamelizeResource::collection($result['data']),
            ]);
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('DnsTool providerAccounts error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * DNS Provider 账号详情。
     */
    public function providerAccountDetail(IdRequest $request): JsonResponse
    {
        try {
            $account = $this->adminService->providerAccountDetail((int) $request->validated()['id']);
            return $this->ok(CamelizeResource::make($account));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('DnsTool providerAccountDetail error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 新增 DNS Provider 账号。
     */
    public function createProviderAccount(DnsProviderAccountStoreRequest $request): JsonResponse
    {
        try {
            $account = $this->adminService->providerAccountStore($request->validated());
            return $this->ok(CamelizeResource::make($account));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('DnsTool createProviderAccount error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 更新 DNS Provider 账号。
     */
    public function updateProviderAccount(DnsProviderAccountUpdateRequest $request): JsonResponse
    {
        try {
            $account = $this->adminService->providerAccountUpdate($request->validated());
            return $this->ok(CamelizeResource::make($account));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('DnsTool updateProviderAccount error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * DNS 域名列表（只读）。
     */
    public function domains(DnsDomainIndexRequest $request): JsonResponse
    {
        try {
            $result = $this->adminService->domainIndex($request->validated());

            return $this->ok([
                'page' => $result['page'],
                'pageSize' => $result['pageSize'],
                'total' => $result['total'],
                'data' => CamelizeResource::collection($result['data']),
            ]);
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('DnsTool domains error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 更新 DNS 域名 note/tags（仅允许元信息字段）。
     */
    public function updateDomainMeta(DnsDomainUpdateMetaRequest $request): JsonResponse
    {
        try {
            $params = $request->validated();
            $domain = $this->adminService->domainUpdateMeta((int) $params['id'], $params);
            return $this->ok(CamelizeResource::make($domain));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('DnsTool updateDomainMeta error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 外部 DNS 服务同步域名。
     */
    public function syncDomains(DnsDomainSyncRequest $request): JsonResponse
    {
        try {
            $params = $request->validated();
            $providerAccountId = isset($params['providerAccountId']) ? (int) $params['providerAccountId'] : null;
            $result = $this->dnsToolService->syncDomains($providerAccountId);
            return $this->ok($result);
        } catch (\RuntimeException $e) {
            return $this->error([$e->getCode() ?: 500, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('DnsTool syncDomains error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * DNS 绑定记录列表（只读）。
     */
    public function ipBindings(DnsIpBindingIndexRequest $request): JsonResponse
    {
        try {
            $result = $this->adminService->ipBindingIndex($request->validated());

            return $this->ok([
                'page' => $result['page'],
                'pageSize' => $result['pageSize'],
                'total' => $result['total'],
                'data' => CamelizeResource::collection($result['data']),
            ]);
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('DnsTool ipBindings error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 本地库按 IP 查询绑定记录。
     */
    public function recordsByIp(DnsRecordsByIpRequest $request): JsonResponse
    {
        try {
            $params = $request->validated();
            $result = $this->adminService->recordsByIp($params['ipv4'], $params['status'] ?? 'active');
            return $this->ok(CamelizeResource::collection($result));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('DnsTool recordsByIp error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 更新 DNS 绑定记录 note/tags（仅允许元信息字段）。
     */
    public function updateIpBindingMeta(DnsIpBindingUpdateMetaRequest $request): JsonResponse
    {
        try {
            $params = $request->validated();
            $binding = $this->adminService->ipBindingUpdateMeta((int) $params['id'], $params);
            return $this->ok(CamelizeResource::make($binding));
        } catch (BusinessException $e) {
            return $this->error([$e->getCode(), $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('DnsTool updateIpBindingMeta error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 外部 DNS 服务执行解析。
     */
    public function resolveRecord(DnsRecordResolveRequest $request): JsonResponse
    {
        try {
            $params = $request->validated();
            $result = $this->dnsToolService->resolveRecord(
                $params['ipv4'],
                $params['subdomain'],
                $params['domain'],
                (bool) ($params['unique'] ?? false)
            );

            return $this->ok($result);
        } catch (\RuntimeException $e) {
            return $this->error([$e->getCode() ?: 500, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('DnsTool resolveRecord error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    /**
     * 外部 DNS 服务解绑记录。
     */
    public function unbindRecord(DnsRecordUnbindRequest $request): JsonResponse
    {
        try {
            $params = $request->validated();
            $result = $this->dnsToolService->unbindRecord($params['ipv4'], $params['fqdn']);
            return $this->ok($result);
        } catch (\RuntimeException $e) {
            return $this->error([$e->getCode() ?: 500, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('DnsTool unbindRecord error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }
}
