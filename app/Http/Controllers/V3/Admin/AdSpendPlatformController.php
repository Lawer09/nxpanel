<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdSpendDailyReport;
use App\Models\AdSpendPlatformAccount;
use App\Models\AdSpendSyncJob;
use App\Services\AdSpendPlatformService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdSpendPlatformController extends Controller
{
    public function fetchAccounts(Request $request): JsonResponse
    {
        try {
            $this->normalizeQueryParams($request);

            $request->validate([
                'platformCode' => 'nullable|string|max:50',
                'enabled' => 'nullable|integer|in:0,1',
                'keyword' => 'nullable|string|max:100',
                'page' => 'nullable|integer|min:1',
                'pageSize' => 'nullable|integer|min:1|max:200',
            ]);

            $query = AdSpendPlatformAccount::query();

            if ($request->filled('platformCode')) {
                $query->where('platform_code', $request->input('platformCode'));
            }
            if ($request->filled('enabled')) {
                $query->where('enabled', (int) $request->input('enabled'));
            }
            if ($request->filled('keyword')) {
                $keyword = $request->input('keyword');
                $query->where(function ($q) use ($keyword) {
                    $q->where('account_name', 'like', "%{$keyword}%")
                        ->orWhere('username', 'like', "%{$keyword}%");
                });
            }

            $page = (int) $request->input('page', 1);
            $pageSize = (int) $request->input('pageSize', 20);

            $total = $query->count();
            $rows = $query->orderByDesc('id')
                ->offset(($page - 1) * $pageSize)
                ->limit($pageSize)
                ->get(['id', 'platform_code', 'account_name', 'base_url', 'username', 'enabled', 'last_sync_at', 'remark', 'created_at', 'updated_at']);

            $list = $rows->map(function ($row) {
                return [
                    'id' => (int) $row->id,
                    'platformCode' => $row->platform_code,
                    'accountName' => $row->account_name,
                    'baseUrl' => $row->base_url,
                    'username' => $row->username,
                    'enabled' => (int) $row->enabled,
                    'lastSyncAt' => $row->last_sync_at,
                    'remark' => $row->remark,
                    'createdAt' => $row->created_at,
                    'updatedAt' => $row->updated_at,
                ];
            });

            return $this->ok([
                'list' => $list,
                'total' => $total,
                'page' => $page,
                'pageSize' => $pageSize,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('AdSpendPlatform fetchAccounts error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    public function detailAccount(int $id): JsonResponse
    {
        try {
            $account = AdSpendPlatformAccount::find($id);
            if (!$account) {
                return $this->error([404, '账号不存在']);
            }

            return $this->ok([
                'id' => $account->id,
                'platformCode' => $account->platform_code,
                'accountName' => $account->account_name,
                'baseUrl' => $account->base_url,
                'username' => $account->username,
                'passwordMasked' => '******',
                'enabled' => (int) $account->enabled,
                'lastSyncAt' => $account->last_sync_at,
                'remark' => $account->remark,
            ]);
        } catch (\Exception $e) {
            Log::error('AdSpendPlatform detailAccount error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    public function saveAccount(Request $request): JsonResponse
    {
        try {
            $this->normalizeQueryParams($request);

            $request->validate([
                'platformCode' => 'required|string|max:50',
                'accountName' => 'required|string|max:100',
                'baseUrl' => 'required|string|max:255',
                'username' => 'required|string|max:100',
                'password' => 'required|string|max:255',
                'enabled' => 'nullable|integer|in:0,1',
                'remark' => 'nullable|string|max:255',
            ]);

            $exists = AdSpendPlatformAccount::where('platform_code', $request->input('platformCode'))
                ->where('account_name', $request->input('accountName'))
                ->exists();
            if ($exists) {
                return $this->error([422, '该平台下账号名称已存在']);
            }

            $account = AdSpendPlatformAccount::create([
                'platform_code' => $request->input('platformCode'),
                'account_name' => $request->input('accountName'),
                'base_url' => $request->input('baseUrl'),
                'username' => $request->input('username'),
                'password' => $request->input('password'),
                'enabled' => $request->input('enabled', 1),
                'remark' => $request->input('remark'),
            ]);

            return $this->ok(['id' => $account->id]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('AdSpendPlatform saveAccount error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    public function updateAccount(Request $request, int $id): JsonResponse
    {
        try {
            $this->normalizeQueryParams($request);

            $account = AdSpendPlatformAccount::find($id);
            if (!$account) {
                return $this->error([404, '账号不存在']);
            }

            $request->validate([
                'platformCode' => 'nullable|string|max:50',
                'accountName' => 'nullable|string|max:100',
                'baseUrl' => 'nullable|string|max:255',
                'username' => 'nullable|string|max:100',
                'password' => 'nullable|string|max:255',
                'enabled' => 'nullable|integer|in:0,1',
                'remark' => 'nullable|string|max:255',
            ]);

            $platformCode = $request->input('platformCode', $account->platform_code);
            $accountName = $request->input('accountName', $account->account_name);

            $exists = AdSpendPlatformAccount::where('platform_code', $platformCode)
                ->where('account_name', $accountName)
                ->where('id', '!=', $id)
                ->exists();
            if ($exists) {
                return $this->error([422, '该平台下账号名称已存在']);
            }

            $update = [];
            if ($request->has('platformCode')) {
                $update['platform_code'] = $request->input('platformCode');
            }
            if ($request->has('accountName')) {
                $update['account_name'] = $request->input('accountName');
            }
            if ($request->has('baseUrl')) {
                $update['base_url'] = $request->input('baseUrl');
            }
            if ($request->has('username')) {
                $update['username'] = $request->input('username');
            }
            if ($request->has('enabled')) {
                $update['enabled'] = (int) $request->input('enabled');
            }
            if ($request->has('remark')) {
                $update['remark'] = $request->input('remark');
            }

            if ($request->has('password')) {
                $newPassword = (string) $request->input('password', '');
                if ($newPassword !== '') {
                    $update['password'] = $newPassword;
                    $update['access_token'] = null;
                    $update['token_expired_at'] = null;
                }
            }

            if (!empty($update)) {
                $account->update($update);
            }

            return $this->ok(true);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('AdSpendPlatform updateAccount error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    public function updateAccountStatus(Request $request, int $id): JsonResponse
    {
        try {
            $this->normalizeQueryParams($request);

            $request->validate([
                'enabled' => 'required|integer|in:0,1',
            ]);

            $account = AdSpendPlatformAccount::find($id);
            if (!$account) {
                return $this->error([404, '账号不存在']);
            }

            $account->update(['enabled' => (int) $request->input('enabled')]);
            return $this->ok(true);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('AdSpendPlatform updateAccountStatus error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    public function testAccount(int $id, AdSpendPlatformService $service): JsonResponse
    {
        try {
            $account = AdSpendPlatformAccount::find($id);
            if (!$account) {
                return $this->error([404, '账号不存在']);
            }

            $service->login($account, true);

            return $this->ok([
                'loginSuccess' => true,
            ]);
        } catch (\Exception $e) {
            Log::error('AdSpendPlatform testAccount error: ' . $e->getMessage());
            return $this->error([422, '测试失败: ' . $e->getMessage()]);
        }
    }

    public function sync(Request $request, AdSpendPlatformService $service): JsonResponse
    {
        $job = null;

        try {
            $this->normalizeQueryParams($request);

            $request->validate([
                'accountId' => 'required|integer',
                'startDate' => 'required|date',
                'endDate' => 'required|date|after_or_equal:startDate',
            ]);

            $accountId = (int) $request->input('accountId');
            $startDate = $request->input('startDate');
            $endDate = $request->input('endDate');

            $account = AdSpendPlatformAccount::find($accountId);
            if (!$account) {
                return $this->error([404, '账号不存在']);
            }
            if ((int) $account->enabled !== 1) {
                return $this->error([422, '账号已禁用']);
            }

            $requestParams = [
                'objectName' => 'account',
                'dims' => ['date', 'group_id', 'country'],
                'startDate' => $startDate,
                'endDate' => $endDate,
                'size' => 200,
            ];

            $job = AdSpendSyncJob::create([
                'platform_account_id' => $account->id,
                'platform_code' => $account->platform_code,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'status' => AdSpendSyncJob::STATUS_RUNNING,
                'request_params' => $requestParams,
                'total_records' => 0,
                'matched_records' => 0,
                'unmatched_records' => 0,
            ]);

            $records = $service->fetchDailyRecords($account, $startDate, $endDate, 200);

            $totalRecords = 0;
            $matchedRecords = 0;
            $unmatchedRecords = 0;

            foreach ($records as $record) {
                if (!is_array($record)) {
                    continue;
                }

                $projectCode = trim((string) ($record['groupName'] ?? $record['group_name'] ?? ''));
                $reportDate = (string) ($record['date'] ?? '');
                if ($projectCode === '' || $reportDate === '') {
                    continue;
                }

                $country = (string) ($record['country'] ?? '');
                $country = $country === 'null' ? '' : $country;

                $impressions = (int) ($record['impressions'] ?? 0);
                $clicks = (int) ($record['clicks'] ?? 0);
                $spend = $this->toDecimal($record['spend'] ?? 0, true);
                $ctr = $this->toDecimal($record['ctr'] ?? null, false);
                $cpm = $this->toDecimal($record['cpm'] ?? null, false);
                $cpc = $this->toDecimal($record['cpc'] ?? null, false);

                $totalRecords++;

                AdSpendDailyReport::updateOrCreate(
                    [
                        'platform_account_id' => $account->id,
                        'project_code' => $projectCode,
                        'report_date' => $reportDate,
                        'country' => $country,
                    ],
                    [
                        'platform_code' => $account->platform_code,
                        'project_code' => $projectCode,
                        'impressions' => $impressions,
                        'clicks' => $clicks,
                        'spend' => $spend,
                        'ctr' => $ctr,
                        'cpm' => $cpm,
                        'cpc' => $cpc,
                        'raw_group_name' => $projectCode,
                    ]
                );
                $matchedRecords++;
            }

            $job->update([
                'status' => AdSpendSyncJob::STATUS_SUCCESS,
                'total_records' => $totalRecords,
                'matched_records' => $matchedRecords,
                'unmatched_records' => $unmatchedRecords,
                'error_message' => null,
            ]);

            $account->update(['last_sync_at' => now()]);

            return $this->ok([
                'jobId' => $job->id,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (\Exception $e) {
            if ($job) {
                $job->update([
                    'status' => AdSpendSyncJob::STATUS_FAILED,
                    'error_message' => mb_substr($e->getMessage(), 0, 2000),
                ]);
            }

            Log::error('AdSpendPlatform sync error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    public function fetchSyncJobs(Request $request): JsonResponse
    {
        try {
            $this->normalizeQueryParams($request);

            $request->validate([
                'account_id' => 'nullable|integer',
                'platform_code' => 'nullable|string|max:50',
                'status' => 'nullable|string|in:running,success,failed',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date',
                'page' => 'nullable|integer|min:1',
                'page_size' => 'nullable|integer|min:1|max:200',
            ]);

            $query = AdSpendSyncJob::query();

            if ($request->filled('account_id')) {
                $query->where('platform_account_id', (int) $request->input('account_id'));
            }
            if ($request->filled('platform_code')) {
                $query->where('platform_code', $request->input('platform_code'));
            }
            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }
            if ($request->filled('start_date')) {
                $query->where('start_date', '>=', $request->input('start_date'));
            }
            if ($request->filled('end_date')) {
                $query->where('end_date', '<=', $request->input('end_date'));
            }

            $page = (int) $request->input('page', 1);
            $pageSize = (int) $request->input('page_size', 20);

            $total = $query->count();
            $items = $query->orderByDesc('id')
                ->offset(($page - 1) * $pageSize)
                ->limit($pageSize)
                ->get();

            $accountMap = AdSpendPlatformAccount::whereIn('id', $items->pluck('platform_account_id')->unique()->values())
                ->pluck('account_name', 'id');

            $list = $items->map(function ($item) use ($accountMap) {
                return [
                    'id' => $item->id,
                    'platformAccountId' => $item->platform_account_id,
                    'platformCode' => $item->platform_code,
                    'accountName' => $accountMap[$item->platform_account_id] ?? '',
                    'startDate' => $item->start_date,
                    'endDate' => $item->end_date,
                    'status' => $item->status,
                    'totalRecords' => $item->total_records,
                    'successRecords' => $item->matched_records,
                    'matchedRecords' => $item->matched_records,
                    'unmatchedRecords' => $item->unmatched_records,
                    'errorMessage' => $item->error_message,
                    'createdAt' => $item->created_at,
                    'updatedAt' => $item->updated_at,
                ];
            });

            return $this->ok([
                'list' => $list,
                'total' => $total,
                'page' => $page,
                'pageSize' => $pageSize,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('AdSpendPlatform fetchSyncJobs error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    public function detailSyncJob(int $id): JsonResponse
    {
        try {
            $job = AdSpendSyncJob::find($id);
            if (!$job) {
                return $this->error([404, '同步任务不存在']);
            }

            $account = AdSpendPlatformAccount::find($job->platform_account_id);

            return $this->ok([
                'id' => $job->id,
                'platformAccountId' => $job->platform_account_id,
                'platformCode' => $job->platform_code,
                'accountName' => $account?->account_name ?? '',
                'startDate' => $job->start_date,
                'endDate' => $job->end_date,
                'status' => $job->status,
                'totalRecords' => $job->total_records,
                'successRecords' => $job->matched_records,
                'matchedRecords' => $job->matched_records,
                'unmatchedRecords' => $job->unmatched_records,
                'requestParams' => $job->request_params,
                'errorMessage' => $job->error_message,
                'createdAt' => $job->created_at,
                'updatedAt' => $job->updated_at,
            ]);
        } catch (\Exception $e) {
            Log::error('AdSpendPlatform detailSyncJob error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    public function daily(Request $request): JsonResponse
    {
        $this->normalizeQueryParams($request);
        return $this->dailyInternal($request, null);
    }

    public function summary(Request $request): JsonResponse
    {
        try {
            $this->normalizeQueryParams($request);

            $request->validate([
                'platform_code' => 'nullable|string|max:50',
                'account_id' => 'nullable|integer',
                'project_code' => 'nullable|string|max:100',
                'country' => 'nullable|string|max:50',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'group_by' => 'nullable|string|in:project,account,country,date',
            ]);

            $groupBy = $request->input('group_by', 'project');
            $base = $this->buildReportBaseQuery($request, null);

            if ($groupBy === 'project') {
                $base->addSelect(DB::raw('project_code as dimension_value'));
                $base->groupBy('project_code');
            } elseif ($groupBy === 'account') {
                $base->addSelect(DB::raw('platform_account_id as dimension_value'));
                $base->groupBy('platform_account_id', 'account_name');
            } elseif ($groupBy === 'country') {
                $base->addSelect(DB::raw('country as dimension_value'));
                $base->groupBy('country');
            } else {
                $base->addSelect(DB::raw('report_date as dimension_value'));
                $base->groupBy('report_date');
            }

            $rows = $base->get();

            $data = $rows->map(function ($row) use ($groupBy) {
                $result = [
                    'impressions' => (int) $row->impressions,
                    'clicks' => (int) $row->clicks,
                    'spend' => $this->formatDecimal($row->spend),
                    'ctr' => $this->formatDecimal($row->ctr),
                    'cpm' => $this->formatDecimal($row->cpm),
                    'cpc' => $this->formatDecimal($row->cpc),
                ];

                if ($groupBy === 'project') {
                    $result['projectCode'] = $row->dimension_value;
                } elseif ($groupBy === 'account') {
                    $result['platformAccountId'] = (int) $row->dimension_value;
                    $result['accountName'] = $row->account_name;
                } elseif ($groupBy === 'country') {
                    $result['country'] = $row->dimension_value;
                } else {
                    $result['date'] = (string) $row->dimension_value;
                }

                return $result;
            });

            return $this->ok($data);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('AdSpendPlatform summary error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    public function trend(Request $request): JsonResponse
    {
        $this->normalizeQueryParams($request);
        return $this->trendInternal($request, null);
    }

    public function projectCodes(Request $request): JsonResponse
    {
        try {
            $this->normalizeQueryParams($request);

            $request->validate([
                'keyword' => 'nullable|string|max:100',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date',
            ]);

            $query = AdSpendDailyReport::query()->select('project_code')->distinct();

            if ($request->filled('keyword')) {
                $query->where('project_code', 'like', '%' . $request->input('keyword') . '%');
            }
            if ($request->filled('start_date')) {
                $query->where('report_date', '>=', $request->input('start_date'));
            }
            if ($request->filled('end_date')) {
                $query->where('report_date', '<=', $request->input('end_date'));
            }

            $data = $query->orderByDesc('project_code')->get()->map(function ($row) {
                return [
                    'projectCode' => $row->project_code,
                ];
            });

            return $this->ok($data);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('AdSpendPlatform projectCodes error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    public function projectDaily(Request $request, string $projectCode): JsonResponse
    {
        $this->normalizeQueryParams($request);
        return $this->dailyInternal($request, $projectCode);
    }

    private function dailyInternal(Request $request, ?string $projectCode): JsonResponse
    {
        try {
            $this->normalizeQueryParams($request);

            $request->validate([
                'platform_code' => 'nullable|string|max:50',
                'account_id' => 'nullable|integer',
                'project_code' => 'nullable|string|max:100',
                'country' => 'nullable|string|max:50',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'page' => 'nullable|integer|min:1',
                'page_size' => 'nullable|integer|min:1|max:200',
            ]);

            $page = (int) $request->input('page', 1);
            $pageSize = (int) $request->input('page_size', 50);

            $query = AdSpendDailyReport::query()
                ->leftJoin('ad_spend_platform_accounts as a', 'a.id', '=', 'ad_spend_platform_daily_reports.platform_account_id')
                ->select([
                    'ad_spend_platform_daily_reports.id',
                    'ad_spend_platform_daily_reports.report_date',
                    'ad_spend_platform_daily_reports.platform_account_id',
                    'ad_spend_platform_daily_reports.platform_code',
                    'a.account_name',
                    'ad_spend_platform_daily_reports.project_code',
                    'ad_spend_platform_daily_reports.country',
                    'ad_spend_platform_daily_reports.impressions',
                    'ad_spend_platform_daily_reports.clicks',
                    'ad_spend_platform_daily_reports.spend',
                    'ad_spend_platform_daily_reports.ctr',
                    'ad_spend_platform_daily_reports.cpm',
                    'ad_spend_platform_daily_reports.cpc',
                    'ad_spend_platform_daily_reports.updated_at',
                ]);

            $this->applyDailyFilters($query, $request, $projectCode);

            $total = (clone $query)->count();
            $rows = $query->orderByDesc('ad_spend_platform_daily_reports.report_date')
                ->orderByDesc('ad_spend_platform_daily_reports.id')
                ->offset(($page - 1) * $pageSize)
                ->limit($pageSize)
                ->get()
                ->map(function ($row) {
                    return [
                        'id' => (int) $row->id,
                        'reportDate' => (string) $row->report_date,
                        'platformAccountId' => (int) $row->platform_account_id,
                        'platformCode' => $row->platform_code,
                        'accountName' => $row->account_name ?? '',
                        'projectCode' => $row->project_code,
                        'country' => $row->country,
                        'impressions' => (int) $row->impressions,
                        'clicks' => (int) $row->clicks,
                        'spend' => $this->formatDecimal($row->spend),
                        'ctr' => $this->formatDecimal($row->ctr),
                        'cpm' => $this->formatDecimal($row->cpm),
                        'cpc' => $this->formatDecimal($row->cpc),
                        'updatedAt' => $row->updated_at,
                    ];
                });

            return $this->ok([
                'list' => $rows,
                'total' => $total,
                'page' => $page,
                'pageSize' => $pageSize,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('AdSpendPlatform dailyInternal error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    private function trendInternal(Request $request, ?string $projectCode): JsonResponse
    {
        try {
            $this->normalizeQueryParams($request);

            $request->validate([
                'platform_code' => 'nullable|string|max:50',
                'account_id' => 'nullable|integer',
                'project_code' => 'nullable|string|max:100',
                'country' => 'nullable|string|max:50',
                'start_date' => 'required|date',
                'end_date' => 'required|date|after_or_equal:start_date',
                'dimension' => 'nullable|string|in:day,month',
            ]);

            $dimension = $request->input('dimension', 'day');
            $timeExpr = $dimension === 'month'
                ? "DATE_FORMAT(ad_spend_platform_daily_reports.report_date, '%Y-%m')"
                : 'ad_spend_platform_daily_reports.report_date';

            $query = AdSpendDailyReport::query()
                ->selectRaw($timeExpr . ' as time')
                ->selectRaw('SUM(ad_spend_platform_daily_reports.impressions) as impressions')
                ->selectRaw('SUM(ad_spend_platform_daily_reports.clicks) as clicks')
                ->selectRaw('SUM(ad_spend_platform_daily_reports.spend) as spend')
                ->selectRaw('ROUND(SUM(ad_spend_platform_daily_reports.clicks) / NULLIF(SUM(ad_spend_platform_daily_reports.impressions), 0) * 100, 6) as ctr')
                ->selectRaw('ROUND(SUM(ad_spend_platform_daily_reports.spend) / NULLIF(SUM(ad_spend_platform_daily_reports.impressions), 0) * 1000, 6) as cpm')
                ->selectRaw('ROUND(SUM(ad_spend_platform_daily_reports.spend) / NULLIF(SUM(ad_spend_platform_daily_reports.clicks), 0), 6) as cpc');

            $this->applyDailyFilters($query, $request, $projectCode);

            $rows = $query->groupBy(DB::raw($timeExpr))
                ->orderBy('time')
                ->get()
                ->map(function ($row) {
                    return [
                        'time' => (string) $row->time,
                        'impressions' => (int) $row->impressions,
                        'clicks' => (int) $row->clicks,
                        'spend' => $this->formatDecimal($row->spend),
                        'ctr' => $this->formatDecimal($row->ctr),
                        'cpm' => $this->formatDecimal($row->cpm),
                        'cpc' => $this->formatDecimal($row->cpc),
                    ];
                });

            return $this->ok($rows);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return $this->error([422, $e->getMessage()]);
        } catch (\Exception $e) {
            Log::error('AdSpendPlatform trendInternal error: ' . $e->getMessage());
            return $this->error([500, $e->getMessage()]);
        }
    }

    private function buildReportBaseQuery(Request $request, ?string $projectCode)
    {
        $query = AdSpendDailyReport::query()
            ->leftJoin('ad_spend_platform_accounts as a', 'a.id', '=', 'ad_spend_platform_daily_reports.platform_account_id')
            ->selectRaw('SUM(ad_spend_platform_daily_reports.impressions) as impressions')
            ->selectRaw('SUM(ad_spend_platform_daily_reports.clicks) as clicks')
            ->selectRaw('SUM(ad_spend_platform_daily_reports.spend) as spend')
            ->selectRaw('ROUND(SUM(ad_spend_platform_daily_reports.clicks) / NULLIF(SUM(ad_spend_platform_daily_reports.impressions), 0) * 100, 6) as ctr')
            ->selectRaw('ROUND(SUM(ad_spend_platform_daily_reports.spend) / NULLIF(SUM(ad_spend_platform_daily_reports.impressions), 0) * 1000, 6) as cpm')
            ->selectRaw('ROUND(SUM(ad_spend_platform_daily_reports.spend) / NULLIF(SUM(ad_spend_platform_daily_reports.clicks), 0), 6) as cpc')
            ->addSelect('a.account_name');

        $this->applyDailyFilters($query, $request, $projectCode);

        return $query;
    }

    private function applyDailyFilters($query, Request $request, ?string $projectCode): void
    {
        if ($request->filled('platform_code')) {
            $query->where('ad_spend_platform_daily_reports.platform_code', $request->input('platform_code'));
        }

        if ($request->filled('account_id')) {
            $query->where('ad_spend_platform_daily_reports.platform_account_id', (int) $request->input('account_id'));
        }

        $code = $projectCode ?: $request->input('project_code');
        if ($code) {
            $query->where('ad_spend_platform_daily_reports.project_code', $code);
        }

        if ($request->has('country')) {
            $query->where('ad_spend_platform_daily_reports.country', (string) $request->input('country', ''));
        }

        if ($request->filled('start_date')) {
            $query->where('ad_spend_platform_daily_reports.report_date', '>=', $request->input('start_date'));
        }
        if ($request->filled('end_date')) {
            $query->where('ad_spend_platform_daily_reports.report_date', '<=', $request->input('end_date'));
        }
    }

    private function normalizeQueryParams(Request $request): void
    {
        $map = [
            'platformCode' => 'platform_code',
            'accountId' => 'account_id',
            'projectCode' => 'project_code',
            'startDate' => 'start_date',
            'endDate' => 'end_date',
            'pageSize' => 'page_size',
            'groupBy' => 'group_by',
            'platform_code' => 'platformCode',
            'account_id' => 'accountId',
            'project_code' => 'projectCode',
            'start_date' => 'startDate',
            'end_date' => 'endDate',
            'page_size' => 'pageSize',
            'group_by' => 'groupBy',
        ];

        $merged = [];
        foreach ($map as $from => $to) {
            if ($request->has($from) && !$request->has($to)) {
                $merged[$to] = $request->input($from);
            }
        }

        if (!empty($merged)) {
            $request->merge($merged);
        }
    }

    private function toDecimal($value, bool $defaultZero)
    {
        if ($value === null || $value === '') {
            return $defaultZero ? 0 : null;
        }

        return is_numeric($value) ? (string) $value : ($defaultZero ? 0 : null);
    }

    private function formatDecimal($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 6, '.', '');
    }
}
