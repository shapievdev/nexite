<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use App\Services\PushSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PushController extends Controller
{
    public function subscribe(Request $request, PushSender $push): JsonResponse
    {
        if (! $push->isConfigured()) {
            return response()->json([
                'message' => 'На сервере не заданы VAPID-ключи (php artisan chat:vapid).',
            ], 503);
        }

        $data = $request->validate([
            'endpoint' => ['required', 'url', 'max:500'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'contentEncoding' => ['nullable', 'string', 'max:20'],
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        PushSubscription::updateOrCreate(
            ['endpoint' => $data['endpoint']],
            [
                'user_id' => Auth::id(),
                'p256dh' => $data['keys']['p256dh'],
                'auth' => $data['keys']['auth'],
                'content_encoding' => $data['contentEncoding'] ?? 'aes128gcm',
                'label' => $data['label'] ?? null,
            ]
        );

        return response()->json(['ok' => true]);
    }

    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:500'],
        ]);

        PushSubscription::where('user_id', Auth::id())
            ->where('endpoint', $data['endpoint'])
            ->delete();

        return response()->json(['ok' => true]);
    }

    /** Проверочное уведомление — чтобы убедиться, что всё настроено. */
    public function test(PushSender $push): JsonResponse
    {
        $sent = $push->sendToUser(Auth::user(), [
            'title' => 'Проверка связи',
            'body' => 'Push-уведомления работают 🎉',
            'url' => route('chat'),
            'tag' => 'chat-test',
        ]);

        return response()->json([
            'sent' => $sent,
            'message' => $sent > 0
                ? 'Уведомление отправлено на '.$sent.' устройств(а).'
                : 'Нет активных подписок на этом аккаунте.',
        ]);
    }
}
