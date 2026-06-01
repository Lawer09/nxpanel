<?php

namespace App\Http\Controllers\V3\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\TgBotSayRequest;
use App\Services\App\TgBotService;
use Illuminate\Http\JsonResponse;

class TgBotController extends Controller
{
    public function __construct(
        protected TgBotService $tgBotService
    ) {}

    /**
     * 接收 TG Bot 上报消息。
     */
    public function say(TgBotSayRequest $request): JsonResponse
    {
        $params = $request->validated();
        $result = $this->tgBotService->say((int) $params['receiveAt'], (string) $params['content']);

        return $this->ok($result);
    }
}
