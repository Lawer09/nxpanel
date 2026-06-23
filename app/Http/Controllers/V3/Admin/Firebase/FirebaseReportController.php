<?php

namespace App\Http\Controllers\V3\Admin\Firebase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FirebaseReportNodeQueryRequest;
use App\Http\Requests\Admin\FirebaseReportSyncRequest;
use App\Http\Requests\Admin\FirebaseReportUserSummaryQueryRequest;
use App\Http\Resources\CamelizeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class FirebaseReportController extends Controller
{
    /**
     * 按日期范围触发 Firebase 报表聚合。
     */
    public function sync(FirebaseReportSyncRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $dateFrom = (string) $validated['dateFrom'];
        $dateTo = (string) $validated['dateTo'];

        $exitCode = Artisan::call('firebase_report:aggregate', [
            '--date-from' => $dateFrom,
            '--date-to' => $dateTo,
        ]);

        return $this->ok([
            'success' => $exitCode === 0,
            'exitCode' => $exitCode,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'message' => trim((string) Artisan::output()),
        ]);
    }

    /**
     * Firebase 用户汇总查询。
     */
    public function queryUserSummary(FirebaseReportUserSummaryQueryRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $dateFrom = $validated['dateFrom'] ?? now()->toDateString();
        $dateTo = $validated['dateTo'] ?? now()->toDateString();
        $hourFrom = $validated['hourFrom'] ?? null;
        $hourTo = $validated['hourTo'] ?? null;
        $groupBy = is_array($validated['groupBy'] ?? null) ? $validated['groupBy'] : [];
        $filters = is_array($validated['filters'] ?? null) ? $validated['filters'] : [];
        $pageSize = (int) ($validated['pageSize'] ?? 50);
        $orderBy = $validated['orderBy'] ?? null;
        $orderDirection = $this->normalizeOrderDirection($validated['orderDirection'] ?? null);

        $query = DB::table('firebase_report_user_summary');
        $this->applyTimeRange($query, $dateFrom, $dateTo, $hourFrom, $hourTo);

        $this->applyWhereIn($query, 'app_id', $filters['appIds'] ?? null);
        $this->applyWhereIn($query, 'app_version', $filters['appVersions'] ?? null);
        $this->applyWhereIn($query, 'platform', $filters['platforms'] ?? null);
        $this->applyWhereIn($query, 'country', $filters['countries'] ?? null);
        $this->applyWhereIn($query, 'network_type', $filters['networkTypes'] ?? null);

        if (!empty($groupBy)) {
            $selects = $this->normalizeGroupBy($groupBy, ['date', 'hour', 'app_id', 'app_version', 'platform', 'country', 'network_type']);
            if (empty($selects)) {
                $selects = ['date', 'hour'];
            }
            $query->selectRaw(
                implode(', ', $selects)
                . ', SUM(new_user_count) as new_user_count'
                . ', SUM(active_device_count) as active_device_count'
                . ', MAX(dau_device_count) as dau_device_count'
                . ', SUM(event_count) as event_count'
            );
            $query->groupBy($selects);

            $sortable = array_values(array_unique(array_merge($selects, ['new_user_count', 'active_device_count', 'dau_device_count', 'event_count'])));
            if (is_string($orderBy) && in_array($orderBy, $sortable, true)) {
                $query->orderBy($orderBy, $orderDirection);
            } else {
                $query->orderByDesc('event_count');
            }
        } else {
            $sortable = ['date', 'hour', 'app_id', 'app_version', 'platform', 'country', 'network_type', 'new_user_count', 'active_device_count', 'dau_device_count', 'event_count', 'id', 'created_at', 'updated_at'];
            if (is_string($orderBy) && in_array($orderBy, $sortable, true)) {
                $query->orderBy($orderBy, $orderDirection);
            } else {
                $query->orderByDesc('date')->orderByDesc('hour')->orderByDesc('id');
            }
        }

        $page = $query->paginate($pageSize);
        return $this->ok([
            'data' => CamelizeResource::collection($page->items()),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'pageSize' => $page->perPage(),
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'hourFrom' => $hourFrom,
            'hourTo' => $hourTo,
            'groupBy' => $groupBy,
        ]);
    }

    /**
     * Firebase 节点汇总查询。
     */
    public function queryNode(FirebaseReportNodeQueryRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $dateFrom = $validated['dateFrom'] ?? now()->toDateString();
        $dateTo = $validated['dateTo'] ?? now()->toDateString();
        $hourFrom = $validated['hourFrom'] ?? null;
        $hourTo = $validated['hourTo'] ?? null;
        $groupBy = is_array($validated['groupBy'] ?? null) ? $validated['groupBy'] : [];
        $filters = is_array($validated['filters'] ?? null) ? $validated['filters'] : [];
        $pageSize = (int) ($validated['pageSize'] ?? 50);
        $orderBy = $validated['orderBy'] ?? null;
        $orderDirection = $this->normalizeOrderDirection($validated['orderDirection'] ?? null);

        $query = DB::table('firebase_report_node');
        $this->applyTimeRange($query, $dateFrom, $dateTo, $hourFrom, $hourTo);

        $this->applyWhereIn($query, 'app_id', $filters['appIds'] ?? null);
        $this->applyWhereIn($query, 'app_version', $filters['appVersions'] ?? null);
        $this->applyWhereIn($query, 'country', $filters['countries'] ?? null);
        $this->applyWhereIn($query, 'node_id', $filters['nodeIds'] ?? null);
        $this->applyWhereIn($query, 'node_host', $filters['nodeHosts'] ?? null);
        $this->applyWhereIn($query, 'node_country', $filters['nodeCountries'] ?? null);
        $this->applyWhereIn($query, 'protocol', $filters['protocols'] ?? null);

        if (!empty($groupBy)) {
            $selects = $this->normalizeGroupBy($groupBy, ['date', 'hour', 'app_id', 'app_version', 'country', 'node_id', 'node_host', 'node_name', 'node_country', 'node_region', 'protocol']);
            if (empty($selects)) {
                $selects = ['date', 'hour'];
            }
            $query->selectRaw(
                implode(', ', $selects)
                . ', SUM(total_count) as total_count'
                . ', SUM(success_count) as success_count'
                . ', SUM(fail_count) as fail_count'
                . ', ROUND(SUM(success_count) / NULLIF(SUM(total_count), 0), 4) as success_rate'
                . ', ROUND(SUM(avg_connect_ms * total_count) / NULLIF(SUM(total_count), 0), 0) as avg_connect_ms'
                . ', MAX(max_connect_ms) as max_connect_ms'
            );
            $query->groupBy($selects);

            $sortable = array_values(array_unique(array_merge($selects, ['total_count', 'success_count', 'fail_count', 'success_rate', 'avg_connect_ms', 'max_connect_ms'])));
            if (is_string($orderBy) && in_array($orderBy, $sortable, true)) {
                $query->orderBy($orderBy, $orderDirection);
            } else {
                $query->orderByDesc('total_count');
            }
        } else {
            $sortable = ['date', 'hour', 'app_id', 'app_version', 'country', 'node_id', 'node_host', 'node_name', 'node_country', 'node_region', 'protocol', 'total_count', 'success_count', 'fail_count', 'success_rate', 'avg_connect_ms', 'max_connect_ms', 'id', 'created_at', 'updated_at'];
            if (is_string($orderBy) && in_array($orderBy, $sortable, true)) {
                $query->orderBy($orderBy, $orderDirection);
            } else {
                $query->orderByDesc('date')->orderByDesc('hour')->orderByDesc('total_count');
            }
        }

        $page = $query->paginate($pageSize);
        return $this->ok([
            'data' => CamelizeResource::collection($page->items()),
            'total' => $page->total(),
            'page' => $page->currentPage(),
            'pageSize' => $page->perPage(),
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'hourFrom' => $hourFrom,
            'hourTo' => $hourTo,
            'groupBy' => $groupBy,
        ]);
    }

    private function normalizeGroupBy(array $groupBy, array $allowed): array
    {
        $allowedMap = array_flip($allowed);
        $normalized = [];
        foreach ($groupBy as $field) {
            if (is_string($field) && isset($allowedMap[$field])) {
                $normalized[$field] = $field;
            }
        }

        return array_values($normalized);
    }

    private function applyTimeRange($query, string $dateFrom, string $dateTo, $hourFrom = null, $hourTo = null): void
    {
        $query->where('date', '>=', $dateFrom)
            ->where('date', '<=', $dateTo);

        if ($hourFrom !== null) {
            $query->where('hour', '>=', (int) $hourFrom);
        }
        if ($hourTo !== null) {
            $query->where('hour', '<=', (int) $hourTo);
        }
    }

    private function applyWhereIn($query, string $field, $values): void
    {
        if (!is_array($values)) {
            return;
        }

        $values = array_values(array_filter($values, function ($value) {
            return $value !== null && $value !== '';
        }));

        if (empty($values)) {
            return;
        }

        $query->whereIn($field, $values);
    }

    private function normalizeOrderDirection($value): string
    {
        return is_string($value) && strtolower($value) === 'asc' ? 'asc' : 'desc';
    }
}
