<?php

namespace App\Services\Dns;

use App\Exceptions\BusinessException;
use App\Models\DnsDomain;
use App\Models\DnsIpBinding;
use App\Models\DnsProvider;
use App\Models\DnsProviderAccount;

class DnsAdminService
{
    /**
     * Provider 列表。
     */
    public function providerIndex(array $params): array
    {
        $query = DnsProvider::query();

        if (!empty($params['keyword'])) {
            $keyword = (string) $params['keyword'];
            $query->where(function ($q) use ($keyword) {
                $q->where('name', 'like', "%{$keyword}%")
                    ->orWhere('tags', 'like', "%{$keyword}%")
                    ->orWhere('note', 'like', "%{$keyword}%");
            });
        }

        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 20);

        $total = $query->count();
        $data = $query->orderByDesc('id')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        return compact('page', 'pageSize', 'total', 'data');
    }

    /**
     * Provider 详情。
     */
    public function providerDetail(int $id): DnsProvider
    {
        $provider = DnsProvider::find($id);
        if (!$provider) {
            throw new BusinessException([404, 'DNS Provider 不存在']);
        }

        return $provider;
    }

    /**
     * 新增 Provider。
     */
    public function providerStore(array $params): DnsProvider
    {
        if (DnsProvider::where('name', $params['name'])->exists()) {
            throw new BusinessException([422, 'Provider 名称已存在']);
        }

        return DnsProvider::create([
            'name' => $params['name'],
            'tags' => $params['tags'] ?? '',
            'note' => $params['note'] ?? '',
            'official_website' => $params['officialWebsite'] ?? null,
            'api_host' => $params['apiHost'] ?? null,
            'request_timeout' => $params['requestTimeout'] ?? 15,
            'rate_limit_per_minute' => $params['rateLimitPerMinute'] ?? 60,
        ]);
    }

    /**
     * 更新 Provider。
     */
    public function providerUpdate(array $params): DnsProvider
    {
        $provider = DnsProvider::find((int) $params['id']);
        if (!$provider) {
            throw new BusinessException([404, 'DNS Provider 不存在']);
        }

        $updateData = [];
        if (array_key_exists('name', $params)) {
            $newName = (string) $params['name'];
            if ($newName !== $provider->name && DnsProvider::where('name', $newName)->exists()) {
                throw new BusinessException([422, 'Provider 名称已存在']);
            }
            $updateData['name'] = $newName;
        }
        if (array_key_exists('tags', $params)) {
            $updateData['tags'] = (string) $params['tags'];
        }
        if (array_key_exists('note', $params)) {
            $updateData['note'] = (string) $params['note'];
        }
        if (array_key_exists('officialWebsite', $params)) {
            $updateData['official_website'] = $params['officialWebsite'];
        }
        if (array_key_exists('apiHost', $params)) {
            $updateData['api_host'] = $params['apiHost'];
        }
        if (array_key_exists('requestTimeout', $params)) {
            $updateData['request_timeout'] = (int) $params['requestTimeout'];
        }
        if (array_key_exists('rateLimitPerMinute', $params)) {
            $updateData['rate_limit_per_minute'] = (int) $params['rateLimitPerMinute'];
        }

        if (!empty($updateData)) {
            $provider->update($updateData);
        }

        return $provider->fresh();
    }

    /**
     * Provider 账号列表。
     */
    public function providerAccountIndex(array $params): array
    {
        $query = DnsProviderAccount::query();

        if (!empty($params['keyword'])) {
            $keyword = (string) $params['keyword'];
            $query->where(function ($q) use ($keyword) {
                $q->where('provider_code', 'like', "%{$keyword}%")
                    ->orWhere('account_name', 'like', "%{$keyword}%")
                    ->orWhere('tags', 'like', "%{$keyword}%")
                    ->orWhere('note', 'like', "%{$keyword}%");
            });
        }
        if (!empty($params['providerCode'])) {
            $query->where('provider_code', $params['providerCode']);
        }
        if (!empty($params['status'])) {
            $query->where('status', $params['status']);
        }

        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 20);

        $total = $query->count();
        $data = $query->orderByDesc('id')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        return compact('page', 'pageSize', 'total', 'data');
    }

    /**
     * Provider 账号详情。
     */
    public function providerAccountDetail(int $id): DnsProviderAccount
    {
        $account = DnsProviderAccount::find($id);
        if (!$account) {
            throw new BusinessException([404, 'DNS Provider 账号不存在']);
        }

        return $account;
    }

    /**
     * 新增 Provider 账号。
     */
    public function providerAccountStore(array $params): DnsProviderAccount
    {
        if (DnsProviderAccount::where('account_name', $params['accountName'])->exists()) {
            throw new BusinessException([422, '账号名称已存在']);
        }

        return DnsProviderAccount::create([
            'provider_code' => $params['providerCode'],
            'account_name' => $params['accountName'],
            'tags' => $params['tags'] ?? '',
            'note' => $params['note'] ?? '',
            'config_json' => $params['configJson'] ?? null,
            'status' => $params['status'] ?? 'active',
            'last_synced_at' => $params['lastSyncedAt'] ?? null,
        ]);
    }

    /**
     * 更新 Provider 账号。
     */
    public function providerAccountUpdate(array $params): DnsProviderAccount
    {
        $account = DnsProviderAccount::find((int) $params['id']);
        if (!$account) {
            throw new BusinessException([404, 'DNS Provider 账号不存在']);
        }

        $updateData = [];
        if (array_key_exists('providerCode', $params)) {
            $updateData['provider_code'] = $params['providerCode'];
        }
        if (array_key_exists('accountName', $params)) {
            $newName = (string) $params['accountName'];
            if ($newName !== $account->account_name && DnsProviderAccount::where('account_name', $newName)->exists()) {
                throw new BusinessException([422, '账号名称已存在']);
            }
            $updateData['account_name'] = $newName;
        }
        if (array_key_exists('tags', $params)) {
            $updateData['tags'] = (string) $params['tags'];
        }
        if (array_key_exists('note', $params)) {
            $updateData['note'] = (string) $params['note'];
        }
        if (array_key_exists('configJson', $params)) {
            $updateData['config_json'] = $params['configJson'];
        }
        if (array_key_exists('status', $params)) {
            $updateData['status'] = $params['status'];
        }
        if (array_key_exists('lastSyncedAt', $params)) {
            $updateData['last_synced_at'] = $params['lastSyncedAt'];
        }

        if (!empty($updateData)) {
            $account->update($updateData);
        }

        return $account->fresh();
    }

    /**
     * 域名列表（只读）。
     */
    public function domainIndex(array $params): array
    {
        $query = DnsDomain::query()
            ->leftJoin('dns_provider_accounts as dpa', 'dpa.id', '=', 'dns_domains.provider_account_id')
            ->select('dns_domains.*', 'dpa.account_name as account_name')
            ->withCount([
                'ipBindings as binding_ip_count' => function ($q) {
                    $q->where('status', 'active');
                },
            ]);

        if (!empty($params['keyword'])) {
            $keyword = (string) $params['keyword'];
            $query->where(function ($q) use ($keyword) {
                $q->where('dns_domains.domain_name', 'like', "%{$keyword}%")
                    ->orWhere('dns_domains.tags', 'like', "%{$keyword}%")
                    ->orWhere('dns_domains.note', 'like', "%{$keyword}%");
            });
        }
        if (!empty($params['providerCode'])) {
            $query->where('dns_domains.provider_code', $params['providerCode']);
        }
        if (array_key_exists('providerAccountId', $params) && $params['providerAccountId'] !== null) {
            $query->where('dns_domains.provider_account_id', (int) $params['providerAccountId']);
        }
        if (!empty($params['syncStatus'])) {
            $query->where('dns_domains.sync_status', $params['syncStatus']);
        }
        if (array_key_exists('isAvailable', $params) && $params['isAvailable'] !== null) {
            $query->where('dns_domains.is_available', (int) $params['isAvailable']);
        }

        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 20);

        $total = $query->count();
        $data = $query->orderByDesc('dns_domains.id')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        return compact('page', 'pageSize', 'total', 'data');
    }

    /**
     * 仅更新 dns_domains 的 note/tags。
     */
    public function domainUpdateMeta(int $id, array $params): DnsDomain
    {
        $domain = DnsDomain::find($id);
        if (!$domain) {
            throw new BusinessException([404, '域名不存在']);
        }

        $domain->update([
            'tags' => $params['tags'] ?? $domain->tags,
            'note' => $params['note'] ?? $domain->note,
        ]);

        return $domain->fresh();
    }

    /**
     * 绑定记录列表（只读）。
     */
    public function ipBindingIndex(array $params): array
    {
        $query = DnsIpBinding::query();

        if (!empty($params['keyword'])) {
            $keyword = (string) $params['keyword'];
            $query->where(function ($q) use ($keyword) {
                $q->where('fqdn', 'like', "%{$keyword}%")
                    ->orWhere('subdomain', 'like', "%{$keyword}%")
                    ->orWhere('ipv4', 'like', "%{$keyword}%")
                    ->orWhere('tags', 'like', "%{$keyword}%")
                    ->orWhere('note', 'like', "%{$keyword}%");
            });
        }
        if (!empty($params['status'])) {
            $query->where('status', $params['status']);
        }
        if (!empty($params['ipv4'])) {
            $query->where('ipv4', $params['ipv4']);
        }
        if (array_key_exists('providerAccountId', $params) && $params['providerAccountId'] !== null) {
            $query->where('provider_account_id', (int) $params['providerAccountId']);
        }
        if (array_key_exists('domainId', $params) && $params['domainId'] !== null) {
            $query->where('domain_id', (int) $params['domainId']);
        }

        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 20);

        $total = $query->count();
        $data = $query->orderByDesc('id')
            ->offset(($page - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        return compact('page', 'pageSize', 'total', 'data');
    }

    /**
     * 本地库按 IP 查询绑定记录。
     */
    public function recordsByIp(string $ipv4, ?string $status = 'active'): array
    {
        $query = DnsIpBinding::query()->where('ipv4', $ipv4);
        if (!empty($status)) {
            $query->where('status', $status);
        }

        return $query->orderByDesc('id')->get()->toArray();
    }

    /**
     * 仅更新 dns_ip_bindings 的 note/tags。
     */
    public function ipBindingUpdateMeta(int $id, array $params): DnsIpBinding
    {
        $binding = DnsIpBinding::find($id);
        if (!$binding) {
            throw new BusinessException([404, '绑定记录不存在']);
        }

        $binding->update([
            'tags' => $params['tags'] ?? $binding->tags,
            'note' => $params['note'] ?? $binding->note,
        ]);

        return $binding->fresh();
    }
}
