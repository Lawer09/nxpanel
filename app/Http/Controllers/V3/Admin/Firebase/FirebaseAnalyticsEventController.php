<?php

namespace App\Http\Controllers\V3\Admin\Firebase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FirebaseAnalyticsEventsRequest;
use App\Http\Requests\Admin\FirebaseAnalyticsRecentEventsRequest;
use App\Services\FirebaseAnalyticsService;
use Illuminate\Http\JsonResponse;

class FirebaseAnalyticsEventController extends Controller
{
    public function __construct(
        protected FirebaseAnalyticsService $service
    ) {}

    public function events(FirebaseAnalyticsEventsRequest $request): JsonResponse
    {
        return $this->ok($this->service->events($request->validated()));
    }

    /**
     * 查询最近接收事件列表（Redis List 分页）。
     */
    public function recentEvents(FirebaseAnalyticsRecentEventsRequest $request): JsonResponse
    {
        return $this->ok($this->service->recentEvents($request->validated()));
    }

    public function detail(string $eventId): JsonResponse
    {
        $detail = $this->service->eventDetail($eventId);
        if (empty($detail)) {
            return $this->error([404, '事件不存在']);
        }
        return $this->ok($detail);
    }
}
