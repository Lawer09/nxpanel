<?php

namespace App\Http\Controllers\V3\Admin\Firebase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FirebaseAnalyticsEventsRequest;
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

    public function detail(string $eventId): JsonResponse
    {
        $detail = $this->service->eventDetail($eventId);
        if (empty($detail)) {
            return $this->error([404, '事件不存在']);
        }
        return $this->ok($detail);
    }
}
