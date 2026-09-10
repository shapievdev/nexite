<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\PushSender;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Уведомляет собеседника о новом сообщении.
 *
 * Запускается через dispatchAfterResponse(): отправитель не ждёт похода
 * к push-сервису, а отдельный воркер очередей для этого не нужен.
 */
class NotifyPeerOfMessage
{
    use Queueable;

    public function __construct(private readonly int $messageId) {}

    public function handle(PushSender $push): void
    {
        $message = Message::with('user')->find($this->messageId);

        if (! $message) {
            return;
        }

        $peer = $message->user->peer();

        // Пропускаем push только если чат прямо сейчас открыт у собеседника
        // на экране — тогда о сообщении сообщит сама страница. Одного лишь
        // факта, что приложение живо и опрашивает сервер, недостаточно:
        // установленный PWA опрашивает и лёжа в кармане.
        if (! $peer || $peer->isActive()) {
            return;
        }

        $push->sendToUser($peer, [
            'title' => $message->user->name,
            'body' => $message->preview(140),
            'url' => route('chat').'?m='.$message->id,
            'tag' => 'chat-message',
        ]);
    }
}
