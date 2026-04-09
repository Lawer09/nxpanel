<?php

namespace App\Http\Controllers\V3\User;

use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Http\Request;
use App\Http\Controllers\V1\User\PlanController as V1PlanController;


class PlanController extends V1PlanController
{
    public function fetch(Request $request)
    {
        $user = User::find($request->user()->id);
        if ($request->input('id')) {
            $plan = Plan::where('id', $request->input('id'))->first();
            if (!$plan) {
                return $this->error([400, __('Subscription plan does not exist')]);
            }
            if (!$this->planService->isPlanAvailableForUser($plan, $user)) {
                return $this->error([400, __('Subscription plan does not exist')]);
            }
            return $this->ok(PlanResource::make($plan));
        }

        $plans = $this->planService->getAvailablePlans();
        return $this->ok(PlanResource::collection($plans));
    }
}
