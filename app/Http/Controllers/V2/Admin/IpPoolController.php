<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\IpPool;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class IpPoolController extends Controller
{
    /**
     * 获取IP池列表
     */
    public function fetch(Request $request)
    {
        $current = $request->input('current', 1);
        $pageSize = $request->input('pageSize', 10);

        $query = IpPool::query();

        // 应用过滤和排序
        $this->applyFiltersAndSorts($request, $query);

        $total = $query->count();
        $items = $query->orderByDesc('created_at')
            ->offset(($current - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        return $this->ok([
            'data' => $items,
            'total' => $total,
            'pageSize' => $pageSize,
            'page' => $current
        ]);
    }

    /**
     * 获取单个IP详情
     */
    public function detail(Request $request)
    {
        $id = $request->input('id');
        $ipPool = IpPool::find($id);

        if (!$ipPool) {
            return $this->error([400202, 'IP池不存在']);
        }

        return $this->ok($ipPool);
    }

    /**
     * 添加IP到池
     */
    public function save(Request $request)
    {
        $validated = $request->validate([
            'ip' => 'required_without:id|ip|unique:v2_ip_pool,ip',
            'bandwidth' => 'nullable|integer|min:0',
            'hostname' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'region' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:2',
            'loc' => 'nullable|string|max:50',
            'org' => 'nullable|string|max:255',
            'postal' => 'nullable|string|max:20',
            'timezone' => 'nullable|string|max:50',
            'readme_url' => 'nullable|url',
            'provider_id' => 'nullable|integer|exists:v2_providers,id',
            'provider_ip_id' => 'nullable|string|max:128',
            'ip_type' => 'nullable|string|max:32',
            'metadata' => 'nullable|array',
            'score' => 'nullable|integer|min:0|max:100',
            'max_load' => 'nullable|integer|min:1',
            'status' => 'nullable|in:active,cooldown',
            'risk_level' => 'nullable|integer|min:0|max:100',
        ]);

        try {
            if ($request->input('id')) {
                // 编辑现有IP
                $ipPool = IpPool::find($request->input('id'));
                if (!$ipPool) {
                    return $this->fail([400202, 'IP不存在']);
                }
                
                // 不允许修改IP地址
                unset($validated['ip']);
                $ipPool->update($validated);
                
                return $this->ok(true);
            } else {
                // 新增IP
                $ipPool = IpPool::create($validated);
                
                return $this->ok($ipPool);
            }
        } catch (\Exception $e) {
            Log::error('IP Pool save failed', ['error' => $e->getMessage()]);
            return $this->error([500, '保存失败']);
        }
    }

    /**
     * 批量删除IP
     */
    public function delete(Request $request)
    {
        $ids = $request->input('ids', []);
        if (empty($ids)) {
            return $this->error([422, 'ID不能为空']);
        }

        try {
            IpPool::whereIn('id', $ids)->delete();
            return $this->ok(true);
        } catch (\Exception $e) {
            Log::error('IP Pool delete failed', ['error' => $e->getMessage()]);
            return $this->error([500, '删除失败']);
        }
    }

    /**
     * 启用IP
     */
    public function enable(Request $request)
    {
        $id = $request->input('id');
        $ipPool = IpPool::find($id);

        if (!$ipPool) {
            return $this->error([400202, 'IP不存在']);
        }

        $ipPool->update([
            'status' => 'active'
        ]);

        return $this->ok(true);
    }

    /**
     * 禁用IP
     */
    public function disable(Request $request)
    {
        $id = $request->input('id');
        $ipPool = IpPool::find($id);

        if (!$ipPool) {
            return $this->error([400202, 'IP不存在']);
        }

        $ipPool->update([
            'status' => 'cooldown'
        ]);

        return $this->ok(true);
    }

    /**
     * 重置IP评分
     */
    public function resetScore(Request $request)
    {
        $id = $request->input('id');
        $score = $request->input('score', 100);

        if ($score < 0 || $score > 100) {
            return $this->error([422, '评分必须在0-100之间']);
        }

        $ipPool = IpPool::find($id);
        if (!$ipPool) {
            return $this->error([400202, 'IP不存在']);
        }

        $ipPool->update([
            'score' => $score
        ]);

        return $this->ok(true);
    }

    /**
     * 获取统计数据
     */
    public function stats(Request $request)
    {
        $total = IpPool::count();
        $active = IpPool::where('status', 'active')->count();
        $cooldown = IpPool::where('status', 'cooldown')->count();
        $avgScore = (int) IpPool::avg('score');
        $avgSuccessRate = (int) IpPool::avg('success_rate');

        // 按国家统计
        $byCountry = IpPool::selectRaw('country, COUNT(*) as count')
            ->whereNotNull('country')
            ->groupBy('country')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        // 高风险IP
        $highRiskIps = IpPool::where('risk_level', '>', 70)
            ->count();

        return $this->ok([
            'total' => $total,
            'active' => $active,
            'cooldown' => $cooldown,
            'avg_score' => $avgScore,
            'avg_success_rate' => $avgSuccessRate,
            'high_risk_count' => $highRiskIps,
            'by_country' => $byCountry
        ]);
    }

    /**
     * 应用过滤和排序
     */
    private function applyFiltersAndSorts(Request $request, Builder $builder): void
    {
        // 按IP搜索
        if ($request->filled('search_ip')) {
            $builder->where('ip', 'like', '%' . $request->input('search_ip') . '%');
        }

        // 按国家过滤
        if ($request->filled('country')) {
            $builder->where('country', $request->input('country'));
        }

        // 按状态过滤
        if ($request->filled('status')) {
            $builder->where('status', $request->input('status'));
        }

        // 按供应商过滤
        if ($request->filled('provider_id')) {
            $builder->where('provider_id', $request->input('provider_id'));
        }

        // 按云服务商IP ID过滤
        if ($request->filled('provider_ip_id')) {
            $builder->where('provider_ip_id', $request->input('provider_ip_id'));
        }

        // 按IP类型过滤
        if ($request->filled('ip_type')) {
            $builder->where('ip_type', $request->input('ip_type'));
        }

        // 按风险等级过滤
        if ($request->filled('risk_level')) {
            $level = $request->input('risk_level');
            if ($level === 'high') {
                $builder->where('risk_level', '>', 70);
            } elseif ($level === 'medium') {
                $builder->whereBetween('risk_level', [30, 70]);
            } elseif ($level === 'low') {
                $builder->where('risk_level', '<', 30);
            }
        }

        // 按成功率过滤
        if ($request->filled('min_success_rate')) {
            $builder->where('success_rate', '>=', $request->input('min_success_rate'));
        }

        // 排序
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $builder->orderBy($sortBy, $sortOrder);
    }


    /**
     * 获取IP详细信息
     */
    public function getIpInfo(Request $request)
    {
        $ip = $request->input('ip');
        
        if (!$ip) {
            return $this->error([422, 'IP不能为空']);
        }

        // 验证IP格式
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return $this->error([422, 'IP地址格式错误']);
        }

        try {
            // 从 ipinfo.io 获取 IP 信息
            $response = Http::timeout(10)->get("https://ipinfo.io/{$ip}/json");
            
            if (!$response->successful()) {
                return $this->error([500, '获取IP信息失败，请稍后重试']);
            }

            $data = $response->json();

            // 验证返回数据是否包含必要字段
            if (!isset($data['ip'])) {
                return $this->error([400, 'IP信息不存在']);
            }

            // 规范化返回数据
            $normalizedData = [
                'ip' => $data['ip'] ?? null,
                'hostname' => $data['hostname'] ?? null,
                'city' => $data['city'] ?? null,
                'region' => $data['region'] ?? null,
                'country' => $data['country'] ?? null,
                'loc' => $data['loc'] ?? null,
                'org' => $data['org'] ?? null,
                'postal' => $data['postal'] ?? null,
                'timezone' => $data['timezone'] ?? null,
                'readme' => $data['readme'] ?? 'https://ipinfo.io/missingauth'
            ];

            return $this->ok($normalizedData);

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('IP Info Connection Failed', [
                'ip' => $ip,
                'error' => $e->getMessage()
            ]);
            return $this->fail([500, '网络连接失败，请检查网络']);
        } catch (\Exception $e) {
            Log::error('IP Info Request Failed', [
                'ip' => $ip,
                'error' => $e->getMessage()
            ]);
            return $this->error([500, '获取IP信息失败']);
        }
    }


    /**  
     * 批量导入IP  
     * 已存在的IP（按ip字段匹配）会更新，不存在的会新增  
     * 返回所有导入记录的id信息  
     */  
    public function batchImport(Request $request)  
    {  
        $items = $request->input('items', []);  
  
        if (empty($items) || !is_array($items)) {  
            return $this->error([422, '导入数据不能为空']);  
        }  
  
        // 允许更新的字段（不包含ip，ip仅用于匹配）  
        $allowedFields = [  
            'bandwidth',
            'hostname', 'city', 'region', 'country', 'loc',  
            'org', 'postal', 'timezone', 'readme_url',  
            'provider_id',
            'provider_ip_id',
            'ip_type',
            'metadata',
            'score', 'max_load', 'status', 'risk_level',  
        ];  
  
        $created = [];  
        $updated = [];  
        $failed = [];  
  
        DB::beginTransaction();  
        try {  
            foreach ($items as $index => $item) {  
                // 验证ip字段必须存在  
                if (!isset($item['ip']) || $item['ip'] === '' || $item['ip'] === null) {  
                    $failed[] = [  
                        'index' => $index,  
                        'ip' => $item['ip'] ?? null,  
                        'reason' => 'IP地址不能为空',  
                    ];  
                    continue;  
                }

                $ips = is_array($item['ip']) ? $item['ip'] : [$item['ip']];

                if (empty($ips)) {
                    $failed[] = [
                        'index' => $index,
                        'ip' => $item['ip'] ?? null,
                        'reason' => 'IP地址不能为空',
                    ];
                    continue;
                }
  
                // 验证 country 长度  
                if (!empty($item['country']) && strlen($item['country']) > 2) {  
                    $failed[] = [  
                        'index' => $index,  
                        'ip' => $item['ip'],  
                        'reason' => '国家代码最多2个字符',  
                    ];  
                    continue;  
                }  
  
                // 验证 status  
                if (!empty($item['status']) && !in_array($item['status'], ['active', 'cooldown'])) {  
                    $failed[] = [  
                        'index' => $index,  
                        'ip' => $item['ip'],  
                        'reason' => '状态只能是 active 或 cooldown',  
                    ];  
                    continue;  
                }  
  
                // 验证 score 和 risk_level 范围  
                if (isset($item['score']) && ($item['score'] < 0 || $item['score'] > 100)) {  
                    $failed[] = [  
                        'index' => $index,  
                        'ip' => $item['ip'],  
                        'reason' => '评分必须在0-100之间',  
                    ];  
                    continue;  
                }  
  
                if (isset($item['risk_level']) && ($item['risk_level'] < 0 || $item['risk_level'] > 100)) {  
                    $failed[] = [  
                        'index' => $index,  
                        'ip' => $item['ip'],  
                        'reason' => '风险值必须在0-100之间',  
                    ];  
                    continue;  
                }  
  
                foreach ($ips as $ip) {
                    if (empty($ip)) {
                        $failed[] = [
                            'index' => $index,
                            'ip' => $ip,
                            'reason' => 'IP地址不能为空',
                        ];
                        continue;
                    }

                    // 验证IP格式
                    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                        $failed[] = [
                            'index' => $index,
                            'ip' => $ip,
                            'reason' => 'IP地址格式错误',
                        ];
                        continue;
                    }

                    // 提取允许的字段  
                    $data = array_intersect_key($item, array_flip($allowedFields));  
  
                    // 检查是否已存在  
                    $existing = IpPool::where('ip', $ip)->first();  
  
                    if ($existing) {  
                        // 更新已有记录  
                        $existing->update($data);  
                        $updated[] = ['id' => $existing->id, 'ip' => $existing->ip];  
                    } else {  
                        // 新增记录  
                        $data['ip'] = $ip;  
                        $newIp = IpPool::create($data);  
                        $created[] = ['id' => $newIp->id, 'ip' => $newIp->ip];  
                    }  
                }
            }  
  
            DB::commit();  
  
            return $this->ok([  
                'created' => $created,  
                'updated' => $updated,  
                'failed' => $failed,  
                'summary' => [  
                    'total' => count($items),  
                    'created_count' => count($created),  
                    'updated_count' => count($updated),  
                    'failed_count' => count($failed),  
                ],  
            ]);  
        } catch (\Exception $e) {  
            DB::rollBack();  
            Log::error('IP Pool batch import failed', ['error' => $e->getMessage()]);  
            return $this->error([500, '批量导入失败: ' . $e->getMessage()]);  
        }  
    }
}