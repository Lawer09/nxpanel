<?php

namespace App\Services\App;

class TgBotService
{
    /**
     * 接收应用侧 TgBot 消息。
     */
    public function say(int $receiveAt, string $content): array
    {
        return [
            'received' => true,
            'receiveAt' => $receiveAt,
            'content' => $content,
        ];
    }
}
