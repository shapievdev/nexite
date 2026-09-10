<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\PushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\TestCase;

class PwaPushTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.vapid', [
            'subject' => 'mailto:chat@localhost',
            'public_key' => 'test-public-key',
            'private_key' => 'test-private-key',
        ]);

        $this->alice = User::create([
            'username' => 'alice', 'name' => 'Алиса', 'access_code' => '1234', 'color' => '#5b8def',
        ]);

        $this->bob = User::create([
            'username' => 'bob', 'name' => 'Борис', 'access_code' => '5678', 'color' => '#e0725c',
        ]);
    }

    private function subscription(array $overrides = []): array
    {
        return array_replace_recursive([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
            'keys' => ['p256dh' => 'BPublicKeyValue', 'auth' => 'AuthSecret'],
            'contentEncoding' => 'aes128gcm',
        ], $overrides);
    }

    public function test_манифест_отдаётся_гостю(): void
    {
        $this->get('/manifest.webmanifest')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json; charset=utf-8')
            ->assertJsonPath('display', 'standalone')
            ->assertJsonPath('start_url', '/')
            ->assertJsonCount(4, 'icons');
    }

    public function test_офлайн_страница_доступна_без_входа(): void
    {
        $this->get('/offline')->assertOk()->assertSee('Нет соединения');
    }

    public function test_service_worker_лежит_в_корне(): void
    {
        $this->assertFileExists(public_path('sw.js'));
        $this->assertFileExists(public_path('icons/icon-192.png'));
        $this->assertFileExists(public_path('icons/icon-512.png'));
        $this->assertFileExists(public_path('icons/icon-maskable-512.png'));
        $this->assertFileExists(public_path('icons/apple-touch-icon.png'));
    }

    public function test_подписка_сохраняется(): void
    {
        $this->actingAs($this->alice)
            ->postJson('/api/push/subscribe', $this->subscription() + ['label' => 'Chrome на Mac'])
            ->assertOk();

        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $this->alice->id,
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
            'label' => 'Chrome на Mac',
        ]);
    }

    public function test_повторная_подписка_не_плодит_записи(): void
    {
        $this->actingAs($this->alice)->postJson('/api/push/subscribe', $this->subscription())->assertOk();
        $this->actingAs($this->alice)->postJson('/api/push/subscribe', $this->subscription())->assertOk();

        $this->assertDatabaseCount('push_subscriptions', 1);
    }

    public function test_подписка_без_ключей_отклоняется(): void
    {
        $this->actingAs($this->alice)
            ->postJson('/api/push/subscribe', ['endpoint' => 'https://example.com/x'])
            ->assertStatus(422);
    }

    public function test_гость_не_может_подписаться(): void
    {
        $this->postJson('/api/push/subscribe', $this->subscription())->assertStatus(401);
    }

    public function test_отписка_убирает_только_свою_запись(): void
    {
        $this->actingAs($this->alice)->postJson('/api/push/subscribe', $this->subscription())->assertOk();
        $this->actingAs($this->bob)->postJson('/api/push/subscribe', $this->subscription([
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/bob',
        ]))->assertOk();

        // Борис пытается отписать чужой endpoint — своя запись остаётся, чужая цела
        $this->actingAs($this->bob)
            ->postJson('/api/push/unsubscribe', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123'])
            ->assertOk();

        $this->assertDatabaseCount('push_subscriptions', 2);

        $this->actingAs($this->bob)
            ->postJson('/api/push/unsubscribe', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/bob'])
            ->assertOk();

        $this->assertDatabaseCount('push_subscriptions', 1);
    }

    public function test_без_vapid_ключей_подписка_возвращает_503(): void
    {
        config()->set('services.vapid.public_key', null);
        config()->set('services.vapid.private_key', null);

        $this->actingAs($this->alice)
            ->postJson('/api/push/subscribe', $this->subscription())
            ->assertStatus(503);
    }

    public function test_push_уходит_офлайн_собеседнику(): void
    {
        $this->bob->forceFill(['last_seen_at' => now()->subHour()])->saveQuietly();

        $this->mock(PushSender::class, function (MockInterface $mock) {
            $mock->shouldReceive('sendToUser')
                ->once()
                ->withArgs(function (User $user, array $payload) {
                    return $user->id === $this->bob->id
                        && $payload['title'] === 'Алиса'
                        && $payload['body'] === 'Ты дома?';
                })
                ->andReturn(1);
        });

        $this->actingAs($this->alice)
            ->postJson('/api/messages', ['body' => 'Ты дома?'])
            ->assertCreated();
    }

    public function test_push_не_дублирует_уведомление_тому_кто_в_чате(): void
    {
        $this->bob->forceFill(['last_seen_at' => now()])->saveQuietly();

        $this->mock(PushSender::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('sendToUser');
        });

        $this->actingAs($this->alice)
            ->postJson('/api/messages', ['body' => 'Ты дома?'])
            ->assertCreated();
    }

    public function test_проверочное_уведомление_без_подписок(): void
    {
        $this->actingAs($this->alice)
            ->postJson('/api/push/test')
            ->assertOk()
            ->assertJsonPath('sent', 0);
    }

    public function test_отправитель_без_подписок_ничего_не_шлёт(): void
    {
        $this->bob->forceFill(['last_seen_at' => now()->subHour()])->saveQuietly();

        $sender = app(PushSender::class);
        $this->assertTrue($sender->isConfigured());
        $this->assertSame(0, $sender->sendToUser($this->bob, ['title' => 'x', 'body' => 'y']));

        PushSubscription::create([
            'user_id' => $this->bob->id,
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/xyz',
            'p256dh' => 'k', 'auth' => 'a',
        ]);

        // С кривыми тестовыми ключами реальная отправка не проходит,
        // но метод обязан отработать без исключений.
        $this->assertSame(0, $sender->sendToUser($this->bob, ['title' => 'x', 'body' => 'y']));
    }

    public function test_страница_чата_подключает_манифест_и_иконки(): void
    {
        $this->actingAs($this->alice)
            ->get('/')
            ->assertOk()
            ->assertSee('rel="manifest"', false)
            ->assertSee('apple-touch-icon.png', false)
            ->assertSee('vapidPublicKey', false);
    }
}
