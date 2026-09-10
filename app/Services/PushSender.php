<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Отправка web-push уведомлений подписанным устройствам.
 *
 * Ключи VAPID берутся из .env (php artisan chat:vapid). Если их нет,
 * отправка просто молча пропускается — чат работает и без push.
 */
class PushSender
{
    public function isConfigured(): bool
    {
        return filled(config('services.vapid.public_key'))
            && filled(config('services.vapid.private_key'));
    }

    /**
     * @param  array{title: string, body: string, url?: string, tag?: string}  $payload
     * @return int число устройств, которым ушло уведомление
     */
    public function sendToUser(User $user, array $payload): int
    {
        if (! $this->isConfigured()) {
            return 0;
        }

        $subscriptions = PushSubscription::where('user_id', $user->id)->get();

        if ($subscriptions->isEmpty()) {
            return 0;
        }

        try {
            $webPush = new WebPush(['VAPID' => [
                'subject' => config('services.vapid.subject'),
                'publicKey' => config('services.vapid.public_key'),
                'privateKey' => config('services.vapid.private_key'),
            ]]);
            $webPush->setReuseVAPIDHeaders(true);
        } catch (\Throwable $e) {
            Log::warning('push: не удалось инициализировать WebPush', ['error' => $e->getMessage()]);

            return 0;
        }

        foreach ($subscriptions as $subscription) {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'publicKey' => $subscription->p256dh,
                    'authToken' => $subscription->auth,
                    'contentEncoding' => $subscription->content_encoding,
                ]),
                json_encode($payload, JSON_UNESCAPED_UNICODE)
            );
        }

        $sent = 0;

        foreach ($webPush->flush() as $report) {
            $endpoint = $report->getRequest()->getUri()->__toString();

            if ($report->isSuccess()) {
                $sent++;

                continue;
            }

            // 404/410 — подписка протухла (приложение удалили, кэш браузера сбросили)
            if ($report->isSubscriptionExpired()) {
                PushSubscription::where('endpoint', $endpoint)->delete();

                continue;
            }

            Log::info('push: устройство не приняло уведомление', [
                'endpoint' => $endpoint,
                'reason' => $report->getReason(),
            ]);
        }

        if ($sent > 0) {
            PushSubscription::whereIn('id', $subscriptions->pluck('id'))
                ->update(['last_sent_at' => now()]);
        }

        return $sent;
    }
}
