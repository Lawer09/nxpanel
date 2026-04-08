<?php

namespace App\Http\Controllers\V3\Client;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Protocols\General;
use App\Services\Plugin\HookManager;
use App\Services\ServerService;
use App\Services\UserService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use App\Http\Controllers\V1\Client\ClientController as V1ClientController;

class ClientController extends V1ClientController
{

    public function subscribe(Request $request)
    {
        HookManager::call('client.subscribe.before');
        $request->validate([
            'types' => ['nullable', 'string'],
            'filter' => ['nullable', 'string'],
            'flag' => ['nullable', 'string'],
        ]);

        $user = $request->user();
        $userService = new UserService();

        if (!$userService->isAvailable($user)) {
            HookManager::call('client.subscribe.unavailable');
            return response('', 403, ['Content-Type' => 'text/plain']);
        }

        $url = $this->doSubscribe($request, $user);

        return $this->ok($url);
    }
   
}
