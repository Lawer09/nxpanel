<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class ProjectReportService
{
    /**
     * 查询项目小时报表。
     */
    public function queryHourly(array $validated): array
    {
        $dateFrom = $validated['dateFrom'] ?? now()->subDays(1)->toDateString();
        $dateTo = $validated['dateTo'] ?? now()->toDateString();
        $hourFrom = $validated['hourFrom'] ?? null;
        $hourTo = $validated['hourTo'] ?? null;
        $groupBy = is_array($validated['groupBy'] ?? null) ? $validated['groupBy'] : [];
        $filters = is_array($validated['filters'] ?? null) ? $validated['filters'] : [];
        $page = (int) ($validated['page'] ?? 1);
        $pageSize = (int) ($validated['pageSize'] ?? 50);
        $orderBy = $validated['orderBy'] ?? null;
        $orderDirection = (is_string($validated['orderDirection'] ?? null) && strtolower((string) $validated['orderDirection']) === 'asc') ? 'asc' : 'desc';

        $dimensionMap = [
            'reportDate' => 'date',
            'hour' => 'hour',
            'projectCode' => 'project_code',
            'country' => 'country',
        ];

        $metricMap = [
            'installUsers' => 'install_users',
            'dauUsers' => 'dau_users',
            'adRevenue' => 'ad_revenue',
            'adSpendCost' => 'ad_spend_cost',
            'ros' => 'ros',
            'id' => 'id',
            'updatedAt' => 'updated_at',
        ];

        $query = DB::table('project_report_hourly')
            ->where('date', '>=', $dateFrom)
            ->where('date', '<=', $dateTo);

        if ($hourFrom !== null) {
            $query->where('hour', '>=', (int) $hourFrom);
        }
        if ($hourTo !== null) {
            $query->where('hour', '<=', (int) $hourTo);
        }

        $projectCodes = is_array($filters['projectCodes'] ?? null) ? $filters['projectCodes'] : [];
        if (!empty($projectCodes)) {
            $query->whereIn('project_code', $projectCodes);
        }

        $countries = is_array($filters['countries'] ?? null) ? $filters['countries'] : [];
        if (!empty($countries)) {
            $query->whereIn('country', array_map(static fn ($country) => strtoupper((string) $country), $countries));
        }

        if (empty($groupBy)) {
            $sortable = array_merge(array_keys($dimensionMap), array_keys($metricMap));
            $orderKey = is_string($orderBy) && in_array($orderBy, $sortable, true) ? $orderBy : 'reportDate';
            $orderColumn = $dimensionMap[$orderKey] ?? $metricMap[$orderKey] ?? 'date';

            $total = (clone $query)->count();
            $rows = $query;
            if ($orderKey === 'ros') {
                $rows->orderByRaw(
                    'CASE WHEN ad_spend_cost = 0 OR dau_users = 0 THEN NULL ELSE (ad_revenue * (install_users / dau_users)) / ad_spend_cost END ' . $orderDirection
                );
            } else {
                $rows->orderBy($orderColumn, $orderDirection);
            }

            $rows = $rows
                ->offset(($page - 1) * $pageSize)
                ->limit($pageSize)
                ->get();
        } else {
            $groupDimensions = array_values(array_unique(array_filter($groupBy, static fn ($item) => is_string($item) && isset($dimensionMap[$item]))));
            if (empty($groupDimensions)) {
                $groupDimensions = ['reportDate', 'hour'];
            }

            $groupColumns = array_map(static fn ($key) => $dimensionMap[$key], $groupDimensions);
            $groupQuery = clone $query;
            foreach ($groupColumns as $groupColumn) {
                $groupQuery->selectRaw($groupColumn);
                $groupQuery->groupBy($groupColumn);
            }

            $groupQuery->selectRaw('SUM(install_users) as install_users')
                ->selectRaw('SUM(dau_users) as dau_users')
                ->selectRaw('SUM(ad_revenue) as ad_revenue')
                ->selectRaw('SUM(ad_spend_cost) as ad_spend_cost')
                ->selectRaw('CASE WHEN SUM(ad_spend_cost)=0 OR SUM(dau_users)=0 THEN NULL ELSE ROUND((SUM(ad_revenue) * (SUM(install_users) / SUM(dau_users))) / SUM(ad_spend_cost),6) END as ros')
                ->selectRaw('MAX(updated_at) as updated_at');

            $sortable = array_values(array_unique(array_merge($groupDimensions, [
                'installUsers', 'dauUsers', 'adRevenue', 'adSpendCost', 'ros', 'updatedAt',
            ])));

            $orderKey = is_string($orderBy) && in_array($orderBy, $sortable, true) ? $orderBy : 'adRevenue';
            $orderColumn = $dimensionMap[$orderKey] ?? $metricMap[$orderKey] ?? 'ad_revenue';

            $countQuery = DB::table(DB::raw("({$groupQuery->toSql()}) as t"))
                ->mergeBindings($groupQuery)
                ->selectRaw('COUNT(*) as cnt')
                ->first();
            $total = (int) ($countQuery->cnt ?? 0);

            $rows = $groupQuery
                ->orderBy($orderColumn, $orderDirection)
                ->offset(($page - 1) * $pageSize)
                ->limit($pageSize)
                ->get();
        }

        $data = $rows->map(function ($row) {
            return [
                'id' => isset($row->id) ? (int) $row->id : null,
                'reportDate' => isset($row->date) ? (string) $row->date : null,
                'hour' => isset($row->hour) ? (int) $row->hour : null,
                'projectCode' => $row->project_code ?? null,
                'country' => $row->country ?? null,
                'installUsers' => (int) ($row->install_users ?? 0),
                'dauUsers' => (int) ($row->dau_users ?? 0),
                'adRevenue' => $this->formatDecimal($row->ad_revenue ?? null),
                'adSpendCost' => $this->formatDecimal($row->ad_spend_cost ?? null),
                'ros' => $this->computeRos(
                    (float) ($row->ad_revenue ?? 0),
                    (int) ($row->install_users ?? 0),
                    (int) ($row->dau_users ?? 0),
                    (float) ($row->ad_spend_cost ?? 0)
                ),
                'updatedAt' => $row->updated_at ?? null,
            ];
        });

        return [
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'hourFrom' => $hourFrom,
            'hourTo' => $hourTo,
            'groupBy' => $groupBy,
        ];
    }

    /**
     * 格式化小数值。
     */
    private function formatDecimal($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 6, '.', '');
    }

    /**
     * 根据口径实时计算 ROS。
     */
    private function computeRos(float $adRevenue, int $installUsers, int $dauUsers, float $adSpendCost): ?string
    {
        if ($adSpendCost == 0.0 || $dauUsers === 0) {
            return null;
        }

        $ros = ($adRevenue * ($installUsers / $dauUsers)) / $adSpendCost;

        return $this->formatDecimal($ros);
    }
}
