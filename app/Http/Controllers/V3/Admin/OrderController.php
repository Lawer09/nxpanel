<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\V2\Admin\OrderController as V2OrderController;
use App\Models\Order;
use App\Services\PlanService;
use Illuminate\Http\Request;

class OrderController extends V2OrderController
{
    /**
     * 订单详情
     * 覆盖 V2：使用 ok/error 格式
     */
    public function detail(Request $request)
    {
        $order = Order::with(['user', 'plan', 'commission_log', 'invite_user'])->find($request->input('id'));
        if (!$order) {
            return $this->error([400202, '订单不存在']);
        }
        if ($order->surplus_order_ids) {
            $order['surplus_orders'] = Order::whereIn('id', $order->surplus_order_ids)->get();
        }
        $order['period'] = PlanService::getLegacyPeriod((string) $order->period);
        return $this->ok($order);
    }

    /**
     * 订单列表（分页）
     * 覆盖 V2：统一分页格式 {data, total, page, pageSize}
     */
    public function fetch(Request $request)
    {
        $current  = (int) $request->input('current', 1);
        $pageSize = (int) $request->input('pageSize', 10);

        $orderModel = Order::with('plan:id,name');

        if ($request->boolean('is_commission')) {
            $orderModel->whereNotNull('invite_user_id')
                ->whereNotIn('status', [0, 2])
                ->where('commission_balance', '>', 0);
        }

        $this->applyFiltersAndSortsPublic($request, $orderModel);

        $paginated = $orderModel
            ->latest('created_at')
            ->paginate(perPage: $pageSize, page: $current);

        $paginated->getCollection()->transform(function ($order) {
            $arr = $order->toArray();
            $arr['period'] = PlanService::getLegacyPeriod((string) $order->period);
            return $arr;
        });

        return $this->ok([
            'data'     => $paginated->items(),
            'total'    => $paginated->total(),
            'page'     => $paginated->currentPage(),
            'pageSize' => $paginated->perPage(),
        ]);
    }

    /**
     * 标记订单已支付
     * 覆盖 V2：使用 ok/error 格式
     */
    public function paid(Request $request)
    {
        $order = Order::where('trade_no', $request->input('trade_no'))->first();
        if (!$order) {
            return $this->error([400202, '订单不存在']);
        }
        if ($order->status !== 0) {
            return $this->error([400, '只能对待支付的订单进行操作']);
        }
        $orderService = new \App\Services\OrderService($order);
        if (!$orderService->paid('manual_operation')) {
            return $this->error([500, '更新失败']);
        }
        return $this->ok(true);
    }

    /**
     * 取消订单
     * 覆盖 V2：使用 ok/error 格式
     */
    public function cancel(Request $request)
    {
        $order = Order::where('trade_no', $request->input('trade_no'))->first();
        if (!$order) {
            return $this->error([400202, '订单不存在']);
        }
        if ($order->status !== 0) {
            return $this->error([400, '只能对待支付的订单进行操作']);
        }
        $orderService = new \App\Services\OrderService($order);
        if (!$orderService->cancel()) {
            return $this->error([400, '更新失败']);
        }
        return $this->ok(true);
    }

    /**
     * 更新订单
     * 覆盖 V2：使用 ok/error 格式
     */
    public function update(\App\Http\Requests\Admin\OrderUpdate $request)
    {
        $params = $request->only(['commission_status']);
        $order  = Order::where('trade_no', $request->input('trade_no'))->first();
        if (!$order) {
            return $this->error([400202, '订单不存在']);
        }
        try {
            $order->update($params);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error($e);
            return $this->error([500, '更新失败']);
        }
        return $this->ok(true);
    }

    /**
     * 分配订单
     * 覆盖 V2：使用 ok/error 格式
     */
    public function assign(\App\Http\Requests\Admin\OrderAssign $request)
    {
        $plan = \App\Models\Plan::find($request->input('plan_id'));
        $user = \App\Models\User::where('email', $request->input('email'))->first();

        if (!$user) {
            return $this->error([400202, '该用户不存在']);
        }
        if (!$plan) {
            return $this->error([400202, '该订阅不存在']);
        }

        $userService = new \App\Services\UserService();
        if ($userService->isNotCompleteOrderByUserId($user->id)) {
            return $this->error([400, '该用户还有待支付的订单，无法分配']);
        }

        try {
            \Illuminate\Support\Facades\DB::beginTransaction();
            $order              = new Order();
            $orderService       = new \App\Services\OrderService($order);
            $order->user_id     = $user->id;
            $order->plan_id     = $plan->id;
            $period             = $request->input('period');
            $order->period      = PlanService::getPeriodKey((string) $period);
            $order->trade_no    = \App\Utils\Helper::guid();
            $order->total_amount = $request->input('total_amount');

            if (PlanService::getPeriodKey((string) $order->period) === \App\Models\Plan::PERIOD_RESET_TRAFFIC) {
                $order->type = Order::TYPE_RESET_TRAFFIC;
            } elseif ($user->plan_id !== null && $order->plan_id !== $user->plan_id) {
                $order->type = Order::TYPE_UPGRADE;
            } elseif ($user->expired_at > time() && $order->plan_id == $user->plan_id) {
                $order->type = Order::TYPE_RENEWAL;
            } else {
                $order->type = Order::TYPE_NEW_PURCHASE;
            }

            $orderService->setInvite($user);

            if (!$order->save()) {
                \Illuminate\Support\Facades\DB::rollBack();
                return $this->error([500, '订单创建失败']);
            }
            \Illuminate\Support\Facades\DB::commit();
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            throw $e;
        }

        return $this->ok($order->trade_no);
    }

    // ----------------------------------------------------------------
    // Helper: expose protected applyFiltersAndSorts for this class
    // ----------------------------------------------------------------

    /**
     * V2 的 applyFiltersAndSorts 是 private，在此重新暴露为 protected。
     */
    protected function applyFiltersAndSortsPublic(Request $request, \Illuminate\Database\Eloquent\Builder $builder): void
    {
        // 复用 V2 的 filter/sort 逻辑（方法访问范围为 private，通过反射调用）
        $ref = new \ReflectionMethod(\App\Http\Controllers\V2\Admin\OrderController::class, 'applyFiltersAndSorts');
        $ref->setAccessible(true);
        $ref->invoke($this, $request, $builder);
    }
}
