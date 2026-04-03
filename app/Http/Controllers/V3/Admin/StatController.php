<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\V2\Admin\StatController as V2StatController;
use App\Models\Server;
use App\Models\StatServer;
use App\Models\StatServerDetail;
use App\Models\StatUser;
use App\Models\User;
use App\Services\StatisticalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatController extends V2StatController
{
    /**
     * 指定用户的每日流量明细（分页）
     *
     * GET stat/getStatUser
     *
     * Query params:
     *   user_id  integer  required  用户 ID
     *   pageSize integer  optional  每页条数，默认 10
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getStatUser(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|integer'
        ]);

        $pageSize = (int) $request->input('pageSize', 10);
        $page     = (int) $request->input('page', 1);
        $paginator = StatUser::orderBy('record_at', 'DESC')
            ->where('user_id', $request->input('user_id'))
            ->paginate($pageSize, ['*'], 'page', $page);

        return $this->ok([
            'data'     => $paginator->items(),
            'total'    => $paginator->total(),
            'page'     => $paginator->currentPage(),
            'pageSize' => $paginator->perPage(),
        ]);
    }

    /**
     * 流量 Top10 + 环比（节点 or 用户）
     *
     * GET stat/getTrafficRank
     *
     * Query params:
     *   type        string   required  节点 "node" 或用户 "user"
     *   start_time  integer  optional  起始时间戳（10 位），默认 7 天前
     *   end_time    integer  optional  结束时间戳（10 位），默认当前时间
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getTrafficRank(Request $request): JsonResponse
    {
        $request->validate([
            'type'       => 'required|in:node,user',
            'start_time' => 'nullable|integer|min:1000000000|max:9999999999',
            'end_time'   => 'nullable|integer|min:1000000000|max:9999999999',
        ]);

        $type              = $request->input('type');
        $startDate         = (int) $request->input('start_time', strtotime('-7 days'));
        $endDate           = (int) $request->input('end_time', time());
        $previousStartDate = $startDate - ($endDate - $startDate);
        $previousEndDate   = $startDate;

        if ($type === 'node') {
            $currentData = StatServer::selectRaw('server_id as id, SUM(u + d) as value')
                ->where('record_at', '>=', $startDate)
                ->where('record_at', '<=', $endDate)
                ->groupBy('server_id')
                ->orderBy('value', 'DESC')
                ->limit(10)
                ->get();

            $previousData = StatServer::selectRaw('server_id as id, SUM(u + d) as value')
                ->where('record_at', '>=', $previousStartDate)
                ->where('record_at', '<', $previousEndDate)
                ->whereIn('server_id', $currentData->pluck('id'))
                ->groupBy('server_id')
                ->get()
                ->keyBy('id');
        } else {
            $currentData = StatUser::selectRaw('user_id as id, SUM(u + d) as value')
                ->where('record_at', '>=', $startDate)
                ->where('record_at', '<=', $endDate)
                ->groupBy('user_id')
                ->orderBy('value', 'DESC')
                ->limit(10)
                ->get();

            $previousData = StatUser::selectRaw('user_id as id, SUM(u + d) as value')
                ->where('record_at', '>=', $previousStartDate)
                ->where('record_at', '<', $previousEndDate)
                ->whereIn('user_id', $currentData->pluck('id'))
                ->groupBy('user_id')
                ->get()
                ->keyBy('id');
        }

        $ids   = $currentData->pluck('id');
        $names = $type === 'node'
            ? Server::whereIn('id', $ids)->pluck('name', 'id')
            : User::whereIn('id', $ids)->pluck('email', 'id');

        $result = [];
        foreach ($currentData as $item) {
            $previousValue = isset($previousData[$item->id]) ? (int) $previousData[$item->id]->value : 0;
            $change        = $previousValue > 0
                ? round(($item->value - $previousValue) / $previousValue * 100, 1)
                : 0;

            $result[] = [
                'id'            => (string) $item->id,
                'name'          => $names[$item->id] ?? ($type === 'node' ? "Node {$item->id}" : "User {$item->id}"),
                'value'         => (int) $item->value,
                'previousValue' => $previousValue,
                'change'        => $change,
                'timestamp'     => date('c', $endDate),
            ];
        }

        return $this->ok([
            'timestamp' => date('c'),
            'list'      => $result,
        ]);
    }

    /**
     * 排行榜（用户消耗 Top20 / 节点流量 Top20）
     *
     * GET stat/getRanking
     *
     * Query params:
     *   type        string   required  "user_consumption_rank" 或 "server_traffic_rank"
     *   start_time  integer  optional  起始时间戳（10 位），默认 30 天前
     *   end_time    integer  optional  结束时间戳（10 位），默认当前时间
     *   limit       integer  optional  返回条数，默认 20，最大 100
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getRanking(Request $request): JsonResponse
    {
        $request->validate([
            'type'       => 'required|in:user_consumption_rank,server_traffic_rank,invite_rank',
            'start_time' => 'nullable|integer|min:1000000000|max:9999999999',
            'end_time'   => 'nullable|integer|min:1000000000|max:9999999999',
            'limit'      => 'nullable|integer|min:1|max:100',
        ]);

        $startTime = (int) $request->input('start_time', strtotime('-30 days'));
        $endTime   = (int) $request->input('end_time', time());
        $limit     = (int) $request->input('limit', 20);

        /** @var StatisticalService $service */
        $service = app(StatisticalService::class);
        $service->setStartAt($startTime);
        $service->setEndAt($endTime);

        $data = $service->getRanking($request->input('type'), $limit);

        return $this->ok([
            'list' => $data,
        ]);
    }

    /**
     * 全量节点实时流量排行
     *
     * GET stat/getServerLastRank
     *
     * 无需参数
     *
     * @return JsonResponse
     */
    public function getServerLastRank(): JsonResponse
    {
        $data = $this->service->getServerRank();
        return $this->ok(['list' => $data]);
    }

    /**
     * 昨日节点流量排行
     *
     * GET stat/getServerYesterdayRank
     *
     * 无需参数
     *
     * @return JsonResponse
     */
    public function getServerYesterdayRank(): JsonResponse
    {
        $data = $this->service->getServerRank('yesterday');
        return $this->ok(['list' => $data]);
    }

    /**
     * 今日/本月/总流量汇总 + 运营概览
     *
     * GET stat/getOverride
     *
     * 无需参数
     *
     * @return JsonResponse
     */
    public function getOverride(Request $request): JsonResponse
    {
        $parent = parent::getOverride($request);
        return $this->ok($parent['data'] ?? $parent);
    }

    /**
     * 综合统计数据（含增长率）
     *
     * GET stat/getStats
     *
     * 无需参数
     *
     * @return JsonResponse
     */
    public function getStats(): JsonResponse
    {
        $parent = parent::getStats();
        return $this->ok($parent['data'] ?? $parent);
    }

    /**
     * 指定节点的每日流量
     *
     * GET stat/getStatServer
     *
     * Query params:
     *   server_id   integer  optional  节点 ID，不填返回所有节点
     *   start_time  integer  optional  起始时间戳（10 位），默认今日 00:00:00
     *   end_time    integer  optional  结束时间戳（10 位），默认今日 23:59:59
     *   page        integer  optional  页码，默认 1
     *   pageSize    integer  optional  每页条数，默认 10
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getStatServer(Request $request): JsonResponse
    {
        $request->validate([
            'server_id'  => 'nullable|integer',
            'start_time' => 'nullable|integer|min:1000000000|max:9999999999',
            'end_time'   => 'nullable|integer|min:1000000000|max:9999999999',
            'page'       => 'nullable|integer|min:1',
            'pageSize'   => 'nullable|integer|min:1|max:100',
        ]);

        $startTime = (int) $request->input('start_time', strtotime('today'));
        $endTime   = (int) $request->input('end_time',   strtotime('tomorrow') - 1);
        $page      = (int) $request->input('page', 1);
        $pageSize  = (int) $request->input('pageSize', 10);

        if ($request->filled('server_id')) {
            // 指定节点：返回每条原始记录（含时间维度）
            $paginator = StatServer::with('server:id,name,type')
                ->where('server_id', (int) $request->input('server_id'))
                ->where('record_at', '>=', $startTime)
                ->where('record_at', '<=', $endTime)
                ->orderBy('record_at', 'DESC')
                ->paginate($pageSize, ['*'], 'page', $page);

            $items = collect($paginator->items())->map(function ($row) {
                return [
                    'id'          => $row->id,
                    'server_id'   => $row->server_id,
                    'server_name' => optional($row->server)->name ?? "Server {$row->server_id}",
                    'server_type' => optional($row->server)->type,
                    'u'           => $row->u,
                    'd'           => $row->d,
                    'total'       => $row->u + $row->d,
                    'record_at'   => $row->record_at,
                ];
            });

            return $this->ok([
                'data'     => $items,
                'total'    => $paginator->total(),
                'page'     => $paginator->currentPage(),
                'pageSize' => $paginator->perPage(),
            ]);
        }

        // 未指定节点：按 server_id 聚合，分页
        $aggregated = StatServer::selectRaw('server_id, SUM(u) as u, SUM(d) as d, SUM(u + d) as total')
            ->where('record_at', '>=', $startTime)
            ->where('record_at', '<=', $endTime)
            ->groupBy('server_id')
            ->orderBy('total', 'DESC')
            ->paginate($pageSize, ['*'], 'page', $page);

        $serverIds = collect($aggregated->items())->pluck('server_id');
        $servers   = Server::whereIn('id', $serverIds)->get()->keyBy('id');

        $items = collect($aggregated->items())->map(function ($row) use ($servers) {
            $server = $servers->get($row->server_id);
            return [
                'server_id'   => $row->server_id,
                'server_name' => $server?->name ?? "Server {$row->server_id}",
                'server_type' => $server?->type,
                'u'           => (int) $row->u,
                'd'           => (int) $row->d,
                'total'       => (int) $row->total,
            ];
        });

        return $this->ok([
            'data'     => $items,
            'total'    => $aggregated->total(),
            'page'     => $aggregated->currentPage(),
            'pageSize' => $aggregated->perPage(),
        ]);
    }

    /**
     * 节点分钟级流量明细查询（v2_stat_server_detail）
     *
     * GET stat/getStatServerDetail
     *
     * Query params:
     *   server_id    integer  optional  节点 ID，不填返回所有节点
     *   start_time   integer  optional  起始时间戳（10 位），默认今日 00:00:00
     *   end_time     integer  optional  结束时间戳（10 位），默认今日 23:59:59
     *   granularity  string   optional  聚合粒度：minute（默认）/ hour / day
     *   page         integer  optional  页码，默认 1
     *   pageSize     integer  optional  每页条数，默认 60，最大 1440
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getStatServerDetail(Request $request): JsonResponse
    {
        $request->validate([
            'server_id'   => 'nullable|integer',
            'start_time'  => 'nullable|integer|min:1000000000|max:9999999999',
            'end_time'    => 'nullable|integer|min:1000000000|max:9999999999',
            'granularity' => 'nullable|in:minute,hour,day',
            'page'        => 'nullable|integer|min:1',
            'pageSize'    => 'nullable|integer|min:1|max:1440',
        ]);

        $startTime   = (int) $request->input('start_time', strtotime('today'));
        $endTime     = (int) $request->input('end_time',   strtotime('tomorrow') - 1);
        $granularity = $request->input('granularity', 'minute');
        $page        = (int) $request->input('page', 1);
        $pageSize    = (int) $request->input('pageSize', 60);

        $query = StatServerDetail::where('record_at', '>=', $startTime)
            ->where('record_at', '<=', $endTime);

        if ($request->filled('server_id')) {
            $query->where('server_id', (int) $request->input('server_id'));
        }

        switch ($granularity) {
            case 'day':
                $query->selectRaw('
                    server_id, server_type,
                    year, month, day,
                    SUM(u) as u, SUM(d) as d, SUM(u + d) as total,
                    MIN(record_at) as record_at
                ')->groupBy('server_id', 'server_type', 'year', 'month', 'day')
                  ->orderBy('record_at', 'ASC');
                break;

            case 'hour':
                $query->selectRaw('
                    server_id, server_type,
                    year, month, day, hour,
                    SUM(u) as u, SUM(d) as d, SUM(u + d) as total,
                    MIN(record_at) as record_at
                ')->groupBy('server_id', 'server_type', 'year', 'month', 'day', 'hour')
                  ->orderBy('record_at', 'ASC');
                break;

            default: // minute — 原始分钟粒度
                $query->selectRaw('
                    server_id, server_type,
                    year, month, day, hour, minute,
                    u, d, (u + d) as total, record_at
                ')->orderBy('record_at', 'ASC');
                break;
        }

        $paginator = $query->paginate($pageSize, ['*'], 'page', $page);
        $serverIds = collect($paginator->items())->pluck('server_id')->unique();
        $servers   = Server::whereIn('id', $serverIds)->get()->keyBy('id');

        $items = collect($paginator->items())->map(function ($row) use ($servers) {
            $server = $servers->get($row->server_id);
            $item = [
                'server_id'   => $row->server_id,
                'server_name' => $server?->name ?? "Server {$row->server_id}",
                'server_type' => $server?->type ?? $row->server_type,
                'u'           => (int) $row->u,
                'd'           => (int) $row->d,
                'total'       => (int) $row->total,
                'record_at'   => (int) $row->record_at,
                'year'        => (int) $row->year,
                'month'       => (int) $row->month,
                'day'         => (int) $row->day,
            ];
            if (isset($row->hour))   $item['hour']   = (int) $row->hour;
            if (isset($row->minute)) $item['minute'] = (int) $row->minute;
            return $item;
        });

        return $this->ok([
            'data'        => $items,
            'total'       => $paginator->total(),
            'page'        => $paginator->currentPage(),
            'pageSize'    => $paginator->perPage(),
            'granularity' => $granularity,
        ]);
    }
}
