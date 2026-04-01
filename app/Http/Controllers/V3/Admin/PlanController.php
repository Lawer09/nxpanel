<?php

namespace App\Http\Controllers\V3\Admin;

use App\Http\Controllers\V2\Admin\PlanController as V2PlanController;
use App\Http\Requests\Admin\PlanSave;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PlanController extends V2PlanController
{
    /**
     * 套餐列表
     * 覆盖 V2：使用 ok 格式
     */
    public function fetch(Request $request)
    {
        $plans = Plan::orderBy('sort', 'ASC')
            ->with(['group:id,name'])
            ->withCount([
                'users',
                'users as active_users_count' => function ($query) {
                    $query->where(function ($q) {
                        $q->where('expired_at', '>', time())
                          ->orWhereNull('expired_at');
                    });
                }
            ])
            ->get();

        return $this->ok($plans);
    }

    /**
     * 创建 / 更新套餐
     * 覆盖 V2：使用 ok/error 格式
     */
    public function save(PlanSave $request)
    {
        $params = $request->validated();

        if ($request->input('id')) {
            $plan = Plan::find($request->input('id'));
            if (!$plan) {
                return $this->error([400202, '该订阅不存在']);
            }

            DB::beginTransaction();
            try {
                if ($request->input('force_update')) {
                    User::where('plan_id', $plan->id)->update([
                        'group_id'       => $params['group_id'],
                        'transfer_enable' => $params['transfer_enable'] * 1073741824,
                        'speed_limit'    => $params['speed_limit'],
                        'device_limit'   => $params['device_limit'],
                    ]);
                }
                $plan->update($params);
                DB::commit();
                return $this->ok(true);
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error($e);
                return $this->error([500, '保存失败']);
            }
        }

        if (!Plan::create($params)) {
            return $this->error([500, '创建失败']);
        }
        return $this->ok(true);
    }

    /**
     * 删除套餐
     * 覆盖 V2：使用 ok/error 格式
     */
    public function drop(Request $request)
    {
        if (Order::where('plan_id', $request->input('id'))->first()) {
            return $this->error([400201, '该订阅下存在订单无法删除']);
        }
        if (User::where('plan_id', $request->input('id'))->first()) {
            return $this->error([400201, '该订阅下存在用户无法删除']);
        }

        $plan = Plan::find($request->input('id'));
        if (!$plan) {
            return $this->error([400202, '该订阅不存在']);
        }

        return $this->ok($plan->delete());
    }

    /**
     * 更新套餐状态字段
     * 覆盖 V2：使用 ok/error 格式
     */
    public function update(Request $request)
    {
        $updateData = $request->only(['show', 'renew', 'sell']);

        $plan = Plan::find($request->input('id'));
        if (!$plan) {
            return $this->error([400202, '该订阅不存在']);
        }

        try {
            $plan->update($updateData);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->error([500, '保存失败']);
        }

        return $this->ok(true);
    }

    /**
     * 套餐排序
     * 覆盖 V2：使用 ok/error 格式
     */
    public function sort(Request $request)
    {
        $params = $request->validate([
            'ids' => 'required|array'
        ]);

        try {
            DB::beginTransaction();
            foreach ($params['ids'] as $k => $v) {
                if (!Plan::find($v)->update(['sort' => $k + 1])) {
                    throw new \Exception();
                }
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return $this->error([500, '保存失败']);
        }

        return $this->ok(true);
    }
}
