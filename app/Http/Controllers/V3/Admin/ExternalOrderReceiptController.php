<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ExternalOrderReceiptFetch;
use App\Models\ExternalOrderReceipt;
use Illuminate\Http\JsonResponse;

class ExternalOrderReceiptController extends Controller
{
    /**
     * Query third-party order receipt records with related local user and order info.
     */
    public function fetch(ExternalOrderReceiptFetch $request): JsonResponse
    {
        $params = $request->validated();
        $page = (int) ($params['page'] ?? 1);
        $pageSize = (int) ($params['pageSize'] ?? 20);

        $query = ExternalOrderReceipt::query()
            ->with([
                'user:id,email,telegram_id',
                'localOrder:id,trade_no,status,total_amount,paid_at',
            ])
            ->orderByDesc('id');

        if (!empty($params['provider'])) {
            $query->where('provider', $params['provider']);
        }

        if (!empty($params['status'])) {
            $query->where('status', $params['status']);
        }

        if (!empty($params['externalOrderId'])) {
            $query->where('external_order_id', $params['externalOrderId']);
        }

        if (!empty($params['userId'])) {
            $query->where('user_id', (int) $params['userId']);
        }

        if (!empty($params['localOrderId'])) {
            $query->where('local_order_id', (int) $params['localOrderId']);
        }

        if (!empty($params['transactionId'])) {
            $query->where('transaction_id', $params['transactionId']);
        }

        $result = $query->paginate(perPage: $pageSize, page: $page);

        return $this->ok([
            'data' => $result->items(),
            'total' => $result->total(),
            'page' => $result->currentPage(),
            'pageSize' => $result->perPage(),
        ]);
    }
}
