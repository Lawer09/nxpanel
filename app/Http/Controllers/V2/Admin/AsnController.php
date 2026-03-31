<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\Asn;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class AsnController extends Controller
{
    /**
     * 获取ASN列表
     */
    public function fetch(Request $request)
    {
        $current = $request->input('current', 1);
        $pageSize = $request->input('pageSize', 10);

        $query = Asn::query();

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
     * 添加/编辑ASN
     */
    public function save(Request $request)
    {
        $validated = $request->validate([
            'asn' => 'required_without:id|string|unique:v2_asns,asn,' . $request->input('id'),
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'country' => 'nullable|string|max:2',
            'type' => 'nullable|string|max:50',
            'is_datacenter' => 'nullable|boolean',
            'reliability' => 'nullable|integer|min:0|max:100',
            'reputation' => 'nullable|integer|min:0|max:100',
            'metadata' => 'nullable|json',
        ]);

        try {
            if ($request->input('id')) {
                // 编辑
                $asn = Asn::find($request->input('id'));
                if (!$asn) {
                    return $this->error([400202, 'ASN不存在']);
                }
                
                unset($validated['asn']);
                $asn->update($validated);
                return $this->ok(true);
            } else {
                // 新增
                Asn::create($validated);
                return $this->ok(true);
            }
        } catch (\Exception $e) {
            Log::error('ASN save failed', ['error' => $e->getMessage()]);
            return $this->error([500, '保存失败']);
        }
    }

    /**
     * 删除ASN
     */
    public function delete(Request $request)
    {
        $ids = $request->input('ids', []);
        if (empty($ids)) {
            return $this->error([422, 'ID不能为空']);
        }

        try {
            Asn::whereIn('id', $ids)->delete();
            return $this->ok(true);
        } catch (\Exception $e) {
            Log::error('ASN delete failed', ['error' => $e->getMessage()]);
            return $this->error([500, '删除失败']);
        }
    }

    /**
     * 获取统计数据
     */
    public function stats(Request $request)
    {
        $total = Asn::count();
        $datacenters = Asn::where('is_datacenter', true)->count();
        $highReliability = Asn::where('reliability', '>=', 80)->count();

        $statsByCountry = Asn::getStatsByCountry();
        $statsByType = Asn::selectRaw('type, COUNT(*) as count')
            ->whereNotNull('type')
            ->groupBy('type')
            ->orderByDesc('count')
            ->get();

        return $this->ok([
            'total' => $total,
            'datacenters' => $datacenters,
            'high_reliability' => $highReliability,
            'by_country' => $statsByCountry,
            'by_type' => $statsByType,
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
                $q->where('asn', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('country')) {
            $builder->where('country', $request->input('country'));
        }

        if ($request->filled('type')) {
            $builder->where('type', $request->input('type'));
        }

        if ($request->filled('is_datacenter')) {
            $builder->where('is_datacenter', $request->input('is_datacenter'));
        }

        if ($request->filled('min_reliability')) {
            $builder->where('reliability', '>=', $request->input('min_reliability'));
        }
    }

    /**
     * 获取ASN详情（包含关联的Provider）
     */
    public function detail(Request $request)
    {
        $id = $request->input('id');
        $asn = Asn::with('providers')->find($id);

        if (!$asn) {
            return $this->fail([400202, 'ASN不存在']);
        }

        return $this->success([
            'asn' => $asn,
            'providers_count' => $asn->providers->count(),
            'providers' => $asn->providers()->orderByDesc('reliability')->get(),
        ]);
    }

    /**
     * 获取ASN关联的Provider列表
     */
    public function getProviders(Request $request)
    {
        $asnId = $request->input('asn_id');
        $current = $request->input('current', 1);
        $pageSize = $request->input('pageSize', 10);

        if (!$asnId) {
            return $this->fail([422, 'ASN ID不能为空']);
        }

        $asn = Asn::find($asnId);
        if (!$asn) {
            return $this->fail([400202, 'ASN不存在']);
        }

        $query = $asn->providers();
        $total = $query->count();
        $providers = $query->orderByDesc('reliability')
            ->offset(($current - 1) * $pageSize)
            ->limit($pageSize)
            ->get();

        return $this->success([
            'asn_id' => $asnId,
            'asn' => $asn->asn,
            'data' => $providers,
            'total' => $total,
            'pageSize' => $pageSize,
            'page' => $current
        ]);
    }

    /**
     * 批量关联Provider到ASN
     */
    public function bindProviders(Request $request)
    {
        $validated = $request->validate([
            'asn_id' => 'required|integer|exists:v2_asns,id',
            'provider_ids' => 'required|array|min:1',
            'provider_ids.*' => 'integer|exists:v2_providers,id',
        ]);

        try {
            $asn = Asn::find($validated['asn_id']);
            
            $providers = \App\Models\Provider::whereIn('id', $validated['provider_ids'])->get();

            foreach ($providers as $provider) {
                $provider->update([
                    'asn_id' => $validated['asn_id'],
                    'asn' => $asn->asn,
                ]);
            }

            Log::info('Providers bound to ASN', [
                'asn_id' => $validated['asn_id'],
                'provider_count' => count($validated['provider_ids'])
            ]);

            return $this->success([
                'message' => '关联成功',
                'count' => count($validated['provider_ids'])
            ]);
        } catch (\Exception $e) {
            Log::error('Bind providers failed', ['error' => $e->getMessage()]);
            return $this->fail([500, '关联失败']);
        }
    }

    /**
     * 批量解除Provider与ASN的关联
     */
    public function unbindProviders(Request $request)
    {
        $validated = $request->validate([
            'asn_id' => 'required|integer|exists:v2_asns,id',
            'provider_ids' => 'required|array|min:1',
            'provider_ids.*' => 'integer|exists:v2_providers,id',
        ]);

        try {
            \App\Models\Provider::whereIn('id', $validated['provider_ids'])
                ->where('asn_id', $validated['asn_id'])
                ->update([
                    'asn_id' => null,
                    'asn' => null,
                ]);

            Log::info('Providers unbound from ASN', [
                'asn_id' => $validated['asn_id'],
                'provider_count' => count($validated['provider_ids'])
            ]);

            return $this->success([
                'message' => '解除关联成功',
                'count' => count($validated['provider_ids'])
            ]);
        } catch (\Exception $e) {
            Log::error('Unbind providers failed', ['error' => $e->getMessage()]);
            return $this->fail([500, '解除关联失败']);
        }
    }

    /**  
     * 批量导入ASN  
     * 已存在的ASN（按asn字段匹配）会更新，不存在的会新增  
     * 返回所有导入记录的id信息  
     */  
    public function batchImport(Request $request)  
    {  
        $items = $request->input('items', []);  
  
        if (empty($items) || !is_array($items)) {  
            return $this->error([422, '导入数据不能为空']);  
        }  
  
        $allowedFields = [  
            'name', 'description', 'country', 'type',  
            'is_datacenter', 'reliability', 'reputation', 'metadata',  
        ];  
  
        $created = [];  
        $updated = [];  
        $failed = [];  
  
        DB::beginTransaction();  
        try {  
            foreach ($items as $index => $item) {  
                // asn 字段必须存在  
                if (empty($item['asn'])) {  
                    $failed[] = ['index' => $index, 'asn' => $item['asn'] ?? null, 'reason' => 'ASN号不能为空'];  
                    continue;  
                }  
  
                // name 字段必须存在  
                if (empty($item['name'])) {  
                    $failed[] = ['index' => $index, 'asn' => $item['asn'], 'reason' => 'ASN名称不能为空'];  
                    continue;  
                }  
  
                // 验证 asn 长度  
                if (strlen($item['asn']) > 50) {  
                    $failed[] = ['index' => $index, 'asn' => $item['asn'], 'reason' => 'ASN号最多50个字符'];  
                    continue;  
                }  
  
                // 验证 name 长度  
                if (strlen($item['name']) > 255) {  
                    $failed[] = ['index' => $index, 'asn' => $item['asn'], 'reason' => 'ASN名称最多255个字符'];  
                    continue;  
                }  
  
                // 验证 country 长度  
                if (!empty($item['country']) && strlen($item['country']) > 2) {  
                    $failed[] = ['index' => $index, 'asn' => $item['asn'], 'reason' => '国家代码最多2个字符'];  
                    continue;  
                }  
  
                // 验证 type 长度  
                if (!empty($item['type']) && strlen($item['type']) > 50) {  
                    $failed[] = ['index' => $index, 'asn' => $item['asn'], 'reason' => '类型最多50个字符'];  
                    continue;  
                }  
  
                // 验证 reliability 范围  
                if (isset($item['reliability']) && ($item['reliability'] < 0 || $item['reliability'] > 100)) {  
                    $failed[] = ['index' => $index, 'asn' => $item['asn'], 'reason' => '可靠性必须在0-100之间'];  
                    continue;  
                }  
  
                // 验证 reputation 范围  
                if (isset($item['reputation']) && ($item['reputation'] < 0 || $item['reputation'] > 100)) {  
                    $failed[] = ['index' => $index, 'asn' => $item['asn'], 'reason' => '声誉必须在0-100之间'];  
                    continue;  
                }  
  
                // 验证 metadata 是否为合法 JSON  
                if (!empty($item['metadata']) && is_string($item['metadata'])) {  
                    $decoded = json_decode($item['metadata']);  
                    if (json_last_error() !== JSON_ERROR_NONE) {  
                        $failed[] = ['index' => $index, 'asn' => $item['asn'], 'reason' => 'metadata必须是合法的JSON'];  
                        continue;  
                    }  
                }  
  
                // 提取允许的字段  
                $data = array_intersect_key($item, array_flip($allowedFields));  
  
                // 检查是否已存在  
                $existing = Asn::where('asn', $item['asn'])->first();  
  
                if ($existing) {  
                    $existing->update($data);  
                    $updated[] = ['id' => $existing->id, 'asn' => $existing->asn];  
                } else {  
                    $data['asn'] = $item['asn'];  
                    $newAsn = Asn::create($data);  
                    $created[] = ['id' => $newAsn->id, 'asn' => $newAsn->asn];  
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
            Log::error('ASN batch import failed', ['error' => $e->getMessage()]);  
            return $this->error([500, '批量导入失败: ' . $e->getMessage()]);  
        }  
    }
}