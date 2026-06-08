<?php

namespace App\Http\Controllers\Postback;

use App\Http\Controllers\Controller;
use App\Http\Requests\Postback\PostbackStoreRequest;
use App\Services\PostbackReceiptService;
use Illuminate\Http\JsonResponse;

class PostbackController extends Controller
{
    public function __construct(
        protected PostbackReceiptService $postbackReceiptService
    ) {}

    /**
     * Receive and persist the public click attribution callback.
     */
    public function store(PostbackStoreRequest $request, string $packageName): JsonResponse
    {
        return $this->ok($this->postbackReceiptService->store(
            packageName: $packageName,
            payload: $request->validated(),
            requestIp: $request->ip(),
            userAgent: $request->userAgent()
        ));
    }
}
