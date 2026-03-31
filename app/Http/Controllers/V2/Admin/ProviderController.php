<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\Provider;
use App\Models\Asn;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ProviderController extends Controller
{
    /**
     * 获取Provider列表
     */
    public function fetch(Request $request)
    {
        $current = $request->input('current', 1);
        $pageSize = $request->input('pageSize', 10);

        $query = Provider::with('asn');

        // 应用过滤
        $this->applyFilters($request, $query);

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
     * 添加/编辑Provider
     */
    public function save(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required_without:id|string|unique:v2_providers,name,' . $request->input('id'),
            'description' => 'nullable|string',
            'website' => 'nullable|url',
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:2',
            'type' => 'nullable|string|max:50',
            'asn_id' => 'nullable|integer|exists:v2_asns,id',
            'asn' => 'nullable|string|max:50',
            'reliability' => 'nullable|integer|min:0|max:100',
            'reputation' => 'nullable|integer|min:0|max:100',
            'speed_level' => 'nullable|integer|min:0|max:100',
            'stability' => 'nullable|integer|min:0|max:100',
            'is_active' => 'nullable|boolean',
            'regions' => 'nullable|json',
            'services' => 'nullable|json',
            'metadata' => 'nullable|json',
        ]);

        try {
            if ($request->input('id')) {
                // 编辑
                $provider = Provider::find($request->input('id'));
                if (!$provider) {
                    return $this->error([400202, 'Provider不存在']);
                }
                
                unset($validated['name']);
                $provider->update($validated);
                return $this->ok(true);
            } else {
                // 新增
                Provider::create($validated);
                return $this->ok(true);
            }
        } catch (\Exception $e) {
            Log::error('Provider save failed', ['error' => $e->getMessage()]);
            return $this->error([500, '保存失败']);
        }
    }

    /**
     * 删除Provider
     */
    public function delete(Request $request)
    {
        $ids = $request->input('ids', []);
        if (empty($ids)) {
            return $this->error([422, 'ID不能为空']);
        }

        try {
            Provider::whereIn('id', $ids)->delete();
            return $this->ok(true);
        } catch (\Exception $e) {
            Log::error('Provider delete failed', ['error' => $e->getMessage()]);
            return $this->error([500, '删除失败']);
        }
    }

    /**
     * 批量更新状态
     */
    public function updateStatus(Request $request)
    {
        $validated = $request->validate([
            'ids' => 'required|array',
            'is_active' => 'required|boolean',
        ]);

        try {
            Provider::whereIn('id', $validated['ids'])
                ->update(['is_active' => $validated['is_active']]);
            
            return $this->ok(true);
        } catch (\Exception $e) {
            Log::error('Provider status update failed', ['error' => $e->getMessage()]);
            return $this->error([500, '更新失败']);
        }
    }

    /**
     * 获取统计数据
     */
    public function stats(Request $request)
    {
        $total = Provider::count();
        $active = Provider::where('is_active', true)->count();
        $highReliability = Provider::where('reliability', '>=', 80)->count();

        $statsByType = Provider::getStatsByType();
        $statsByCountry = Provider::getStatsByCountry();

        return $this->ok([
            'total' => $total,
            'active' => $active,
            'high_reliability' => $highReliability,
            'by_type' => $statsByType,
            'by_country' => $statsByCountry,
        ]);
    }

    /**
     * 应用过滤
     */
    private function applyFilters(Request $request, Builder $builder): void
    {
        if ($request->filled('search')) {
            $search = $request->input('search');
            $builder->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('asn', 'like', "%{$search}%");
            });
        }

        if ($request->filled('country')) {
            $builder->where('country', $request->input('country'));
        }

        if ($request->filled('type')) {
            $builder->where('type', $request->input('type'));
        }

        if ($request->filled('is_active')) {
            $builder->where('is_active', $request->input('is_active'));
        }

        if ($request->filled('min_reliability')) {
            $builder->where('reliability', '>=', $request->input('min_reliability'));
        }

        if ($request->filled('asn_id')) {
            $builder->where('asn_id', $request->input('asn_id'));
        }
    }

        /**
     * 获取Provider详情（包含完整的ASN信息）
     */
    public function detail(Request $request)
    {
        $id = $request->input('id');
        $provider = Provider::with('asn')->find($id);

        if (!$provider) {
            return $this->error([400202, 'Provider不存在']);
        }

        return $this->ok($provider);
    }

    /**
     * 批量更新Provider的ASN关联
     */
    public function updateAsn(Request $request)
    {
        $validated = $request->validate([
            'provider_ids' => 'required|array|min:1',
            'provider_ids.*' => 'integer|exists:v2_providers,id',
            'asn_id' => 'required|integer|exists:v2_asns,id',
        ]);

        try {
            $asn = Asn::find($validated['asn_id']);

            Provider::whereIn('id', $validated['provider_ids'])
                ->update([
                    'asn_id' => $validated['asn_id'],
                    'asn' => $asn->asn,
                ]);

            Log::info('Providers ASN updated', [
                'asn_id' => $validated['asn_id'],
                'provider_count' => count($validated['provider_ids'])
            ]);

            return $this->ok([
                'message' => '更新成功',
                'count' => count($validated['provider_ids'])
            ]);
        } catch (\Exception $e) {
            Log::error('Update providers ASN failed', ['error' => $e->getMessage()]);
            return $this->error([500, '更新失败']);
        }
    }

    /**
     * 获取无关联ASN的Provider
     */
    public function getUnboundProviders(Request $request)
    {
        $current = $request->input('current', 1);
        $pageSize = $request->input('pageSize', 10);

        $query = Provider::whereNull('asn_id');
        
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('name', 'like', "%{$search}%");
        }

        $total = $query->count();
        $providers = $query->orderByDesc('created_at')
            ->offset(($current - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        return $this->ok([
            'data' => $providers,
            'total' => $total,
            'pageSize' => $pageSize,
            'page' => $current
        ]);
    }

    /**
     * 获取某个ASN下的所有Provider
     */
    public function getByAsn(Request $request)
    {
        $asnId = $request->input('asn_id');
        $current = $request->input('current', 1);
        $pageSize = $request->input('pageSize', 10);

        if (!$asnId) {
            return $this->error([422, 'ASN ID不能为空']);
        }

        $asn = Asn::find($asnId);
        if (!$asn) {
            return $this->error([400202, 'ASN不存在']);
        }

        $query = Provider::where('asn_id', $asnId);
        $total = $query->count();
        $providers = $query->orderByDesc('reliability')
            ->offset(($current - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        return $this->ok([
            'asn_id' => $asnId,
            'asn_name' => $asn->name,
            'data' => $providers,
            'total' => $total,
            'pageSize' => $pageSize,
            'page' => $current
        ]);
    }

    /**  
     * 批量导入Provider  
     * 已存在的Provider（按name字段匹配）会更新，不存在的会新增  
     * 返回所有导入记录的id信息  
     */  
    public function batchImport(Request $request)  
    {  
        $items = $request->input('items', []);  
  
        if (empty($items) || !is_array($items)) {  
            return $this->error([422, '导入数据不能为空']);  
        }  
  
        $allowedFields = [  
            'description', 'website', 'email', 'phone', 'country', 'type',  
            'asn_id', 'asn', 'reliability', 'reputation', 'speed_level',  
            'stability', 'is_active', 'regions', 'services', 'metadata',  
        ];  
  
        $created = [];  
        $updated = [];  
        $failed = [];  
  
        DB::beginTransaction();  
        try {  
            foreach ($items as $index => $item) {  
                // name 字段必须存在  
                if (empty($item['name'])) {  
                    $failed[] = ['index' => $index, 'name' => $item['name'] ?? null, 'reason' => 'Provider名称不能为空'];  
                    continue;  
                }  
  
                // 验证 country 长度  
                if (!empty($item['country']) && strlen($item['country']) > 2) {  
                    $failed[] = ['index' => $index, 'name' => $item['name'], 'reason' => '国家代码最多2个字符'];  
                    continue;  
                }  
  
                // 验证 type 长度  
                if (!empty($item['type']) && strlen($item['type']) > 50) {  
                    $failed[] = ['index' => $index, 'name' => $item['name'], 'reason' => '类型最多50个字符'];  
                    continue;  
                }  
  
                // 验证 email 格式  
                if (!empty($item['email']) && !filter_var($item['email'], FILTER_VALIDATE_EMAIL)) {  
                    $failed[] = ['index' => $index, 'name' => $item['name'], 'reason' => '邮箱格式不正确'];  
                    continue;  
                }  
  
                // 验证 website 格式  
                if (!empty($item['website']) && !filter_var($item['website'], FILTER_VALIDATE_URL)) {  
                    $failed[] = ['index' => $index, 'name' => $item['name'], 'reason' => '网站URL格式不正确'];  
                    continue;  
                }  
  
                // 验证数值范围字段 (0-100)  
                foreach (['reliability', 'reputation', 'speed_level', 'stability'] as $field) {  
                    if (isset($item[$field]) && ($item[$field] < 0 || $item[$field] > 100)) {  
                        $failed[] = ['index' => $index, 'name' => $item['name'], 'reason' => "{$field}必须在0-100之间"];  
                        continue 2;  
                    }  
                }  
  
                // 验证 asn_id 是否存在  
                if (!empty($item['asn_id'])) {  
                    $asnExists = Asn::find($item['asn_id']);  
                    if (!$asnExists) {  
                        $failed[] = ['index' => $index, 'name' => $item['name'], 'reason' => "关联的ASN ID {$item['asn_id']} 不存在"];  
                        continue;  
                    }  
                }  
  
                // 验证 JSON 字段  
                foreach (['regions', 'services', 'metadata'] as $jsonField) {  
                    if (!empty($item[$jsonField]) && is_string($item[$jsonField])) {  
                        $decoded = json_decode($item[$jsonField]);  
                        if (json_last_error() !== JSON_ERROR_NONE) {  
                            $failed[] = ['index' => $index, 'name' => $item['name'], 'reason' => "{$jsonField}必须是合法的JSON"];  
                            continue 2;  
                        }  
                    }  
                }  
  
                // 提取允许的字段  
                $data = array_intersect_key($item, array_flip($allowedFields));  
  
                // 检查是否已存在  
                $existing = Provider::where('name', $item['name'])->first();  
  
                if ($existing) {  
                    $existing->update($data);  
                    $updated[] = ['id' => $existing->id, 'name' => $existing->name];  
                } else {  
                    $data['name'] = $item['name'];  
                    $newProvider = Provider::create($data);  
                    $created[] = ['id' => $newProvider->id, 'name' => $newProvider->name];  
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
            Log::error('Provider batch import failed', ['error' => $e->getMessage()]);  
            return $this->error([500, '批量导入失败: ' . $e->getMessage()]);  
        }  
    }
}