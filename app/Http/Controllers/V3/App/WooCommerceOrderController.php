<?php

namespace App\Http\Controllers\V3\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\WooCommerceOrderPaidRequest;
use App\Services\WooCommerceOrderReceiptService;
use Illuminate\Http\JsonResponse;

class WooCommerceOrderController extends Controller
{
    public function __construct(
        protected WooCommerceOrderReceiptService $receiptService
    ) {}

    /**
     * Receive a paid WooCommerce order event from an authenticated application client.
     */
    public function paid(WooCommerceOrderPaidRequest $request): JsonResponse
    {
        return $this->ok($this->receiptService->receive($request->validated()));
    }
}
