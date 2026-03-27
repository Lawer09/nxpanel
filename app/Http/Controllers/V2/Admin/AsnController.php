<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\Asn;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

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

        return $this->success([
            'data' => $items,
            'total' => $total,
            'pageSize' => $pageSize,
            'page' => $current
        ]);
    }

    /**
     * 获取ASN详情
     */
    public function detail(Request $request)
    {
        $id = $request->input('id');
        $asn = Asn::with('providers')->find($id);

        if (!$asn) {
            return $this->fail([400202, 'ASN不存在']);
        }

        return $this->success($asn);
    }

    /**
     * 添加/编辑ASN
     */
    public function save(Request $request)
    {
        $validated = $request->validate([
            'asn' => 'required_without:id|string|unique:v2_asns,asn',
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
                    return $this->fail([400202, 'ASN不存在']);
                }
                
                unset($validated['asn']);
                $asn->update($validated);
                return $this->success(true);
            } else {
                // 新增
                Asn::create($validated);
                return $this->success(true);
            }
        } catch (\Exception $e) {
            Log::error('ASN save failed', ['error' => $e->getMessage()]);
            return $this->fail([500, '保存失败']);
        }
    }

    /**
     * 删除ASN
     */
    public function delete(Request $request)
    {
        $ids = $request->input('ids', []);
        if (empty($ids)) {
            return $this->fail([422, 'ID不能为空']);
        }

        try {
            Asn::whereIn('id', $ids)->delete();
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error('ASN delete failed', ['error' => $e->getMessage()]);
            return $this->fail([500, '删除失败']);
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

        return $this->success([
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
}