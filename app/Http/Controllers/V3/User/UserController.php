<?php

namespace App\Http\Controllers\V3\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UserChangePassword;
use App\Http\Requests\User\UserProfileUpdateRequest;
use App\Http\Requests\User\UserTransfer;
use App\Http\Requests\User\UserUpdate;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Auth\LoginService;
use App\Services\AuthService;
use App\Services\Plugin\HookManager;
use App\Services\UserService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Http\Request;
use App\Http\Controllers\V1\User\UserController as V1UserController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

class UserController extends V1UserController
{
    public function info(Request $request)
    {
        $user = User::where('id', $request->user()->id)
            ->select([
                'email',
                'nickname',
                'transfer_enable',
                'last_login_at',
                'created_at',
                'banned',
                'remind_expire',
                'remind_traffic',
                'expired_at',
                'balance',
                'commission_balance',
                'plan_id',
                'discount',
                'commission_rate',
                'telegram_id',
                'uuid',
                'register_metadata'
            ])
            ->first();
        if (!$user) {
            return $this->error([400, __('The user does not exist')]);
        }
        $meta = is_array($user->register_metadata) ? $user->register_metadata : [];
        $user['channel'] = $meta['channel'] ?? null;
        $channelType = $meta['channel_type'] ?? ($meta['channelType'] ?? null);
        $user['channelType'] = $channelType;
        $user['utm_source'] = $meta['utm_source'] ?? null;
        $user['utm_medium'] = $meta['utm_medium'] ?? null;
        $user['utm_campaign'] = $meta['utm_campaign'] ?? null;
        $user['raw_referrer'] = $meta['raw_referrer'] ?? null;
        $user['click_ts'] = isset($meta['click_ts']) ? (int) $meta['click_ts'] : null;
        $user['install_begin_ts'] = isset($meta['install_begin_ts']) ? (int) $meta['install_begin_ts'] : null;
        $user['avatar_url'] = '';
        return $this->ok($user);
    }

    public function profile(Request $request)
    {
        $user = User::query()
            ->where('id', $request->user()->id)
            ->first(['id', 'email', 'nickname', 'is_admin', 'created_at', 'updated_at']);

        if (!$user) {
            return $this->error([400, __('The user does not exist')]);
        }

        return $this->ok($this->formatProfile($user));
    }

    public function updateProfile(UserProfileUpdateRequest $request)
    {
        $user = $request->user();
        if (!$user) {
            return $this->error([400, __('The user does not exist')]);
        }

        $params = $request->validated();
        $user->email = trim((string) $params['email']);
        $nickname = $params['nickname'] ?? null;
        $user->nickname = is_string($nickname) && trim($nickname) !== ''
            ? trim($nickname)
            : null;

        $passwordChanged = !empty($params['password']);
        if ($passwordChanged) {
            $user->password = Hash::make((string) $params['password']);
            $user->password_algo = null;
            $user->password_salt = null;
        }

        if (!$user->save()) {
            return $this->error([400, __('Save failed')]);
        }
        if ((bool) $user->is_admin) {
            Cache::forget('admin:user:options');
        }
        if ($passwordChanged) {
            $user->tokens()->delete();
        }

        return $this->ok($this->formatProfile($user->fresh()));
    }

    public function getSubscribe(Request $request)
    {
        $user = User::where('id', $request->user()->id)
            ->select([
                'plan_id',
                'token',
                'expired_at',
                'u',
                'd',
                'transfer_enable',
                'email',
                'uuid',
                'device_limit',
                'speed_limit',
                'next_reset_at'
            ])
            ->first();
        if (!$user) {
            return $this->error([400, __('The user does not exist')]);
        }
        if ($user->plan_id) {
            $user['plan'] = Plan::find($user->plan_id);
            if (!$user['plan']) {
                return $this->error([400, __('Subscription plan does not exist')]);
            }
        }
        $user['subscribe_url'] = Helper::getSubscribeUrl($user['token']);
        $userService = new UserService();
        $user['reset_day'] = $userService->getResetDay($user);
        $user = HookManager::filter('user.subscribe.response', $user);
        return $this->ok($user);
    }

    public function resetSecurity(Request $request)
    {
        $user = $request->user();
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        if (!$user->save()) {
            return $this->error([400, __('Reset failed')]);
        }
        return $this->ok(Helper::getSubscribeUrl($user->token));
    }

    private function formatProfile(User $user): array
    {
        $nickname = is_string($user->nickname) ? trim($user->nickname) : '';

        return [
            'id' => (int) $user->id,
            'email' => (string) $user->email,
            'nickname' => $nickname !== '' ? $nickname : null,
            'displayName' => $nickname !== '' ? $nickname : (string) $user->email,
            'isAdmin' => (bool) $user->is_admin,
            'createdAt' => $user->created_at,
            'updatedAt' => $user->updated_at,
        ];
    }

}
