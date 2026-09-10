<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = User::create([
            'username' => 'alice', 'name' => 'Алиса', 'access_code' => '1394', 'color' => '#5b8def',
        ]);

        $this->bob = User::create([
            'username' => 'bob', 'name' => 'Борис', 'access_code' => '8261', 'color' => '#e0725c',
        ]);
    }

    public function test_гость_попадает_на_страницу_входа(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_вход_по_коду(): void
    {
        $this->post('/login', ['code' => '1394'])->assertRedirect('/');
        $this->assertAuthenticatedAs($this->alice);
    }

    public function test_вход_вторым_кодом_даёт_второго_пользователя(): void
    {
        $this->post('/login', ['code' => '8261'])->assertRedirect('/');
        $this->assertAuthenticatedAs($this->bob);
    }

    public function test_неверный_код_не_пускает(): void
    {
        $this->post('/login', ['code' => '0000'])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_код_должен_быть_из_четырёх_цифр(): void
    {
        $this->post('/login', ['code' => '12'])->assertSessionHasErrors('code');
        $this->post('/login', ['code' => 'abcd'])->assertSessionHasErrors('code');
    }

    public function test_отправка_и_получение_сообщения(): void
    {
        $this->actingAs($this->alice)
            ->postJson('/api/messages', ['body' => 'Привет!'])
            ->assertCreated()
            ->assertJsonPath('message.body', 'Привет!')
            ->assertJsonPath('message.user_id', $this->alice->id);

        $this->actingAs($this->bob)
            ->getJson('/api/sync?last_id=0')
            ->assertOk()
            ->assertJsonPath('messages.0.body', 'Привет!')
            ->assertJsonPath('peer.name', 'Алиса');
    }

    public function test_пустое_сообщение_отклоняется(): void
    {
        $this->actingAs($this->alice)
            ->postJson('/api/messages', ['body' => '   '])
            ->assertStatus(422);
    }

    public function test_ответ_на_сообщение_содержит_цитату(): void
    {
        $first = Message::create(['user_id' => $this->alice->id, 'body' => 'Вопрос?']);

        $this->actingAs($this->bob)
            ->postJson('/api/messages', ['body' => 'Ответ', 'reply_to_id' => $first->id])
            ->assertCreated()
            ->assertJsonPath('message.reply_to.preview', 'Вопрос?');
    }

    public function test_редактировать_можно_только_своё_сообщение(): void
    {
        $message = Message::create(['user_id' => $this->alice->id, 'body' => 'Моё']);

        $this->actingAs($this->bob)
            ->patchJson("/api/messages/{$message->id}", ['body' => 'Чужое'])
            ->assertForbidden();

        $this->actingAs($this->alice)
            ->patchJson("/api/messages/{$message->id}", ['body' => 'Исправлено'])
            ->assertOk()
            ->assertJsonPath('message.body', 'Исправлено');

        $this->assertNotNull($message->fresh()->edited_at);
    }

    public function test_удалять_можно_только_своё_сообщение(): void
    {
        $message = Message::create(['user_id' => $this->alice->id, 'body' => 'Моё']);

        $this->actingAs($this->bob)->deleteJson("/api/messages/{$message->id}")->assertForbidden();
        $this->actingAs($this->alice)->deleteJson("/api/messages/{$message->id}")->assertOk();

        $this->assertSoftDeleted($message);

        $this->actingAs($this->bob)
            ->getJson('/api/messages')
            ->assertJsonPath('messages.0.deleted', true);
    }

    public function test_реакция_переключается(): void
    {
        $message = Message::create(['user_id' => $this->alice->id, 'body' => 'Смешно']);

        $this->actingAs($this->bob)
            ->postJson("/api/messages/{$message->id}/react", ['emoji' => '😂'])
            ->assertOk()
            ->assertJsonPath('message.reactions.0.emoji', '😂');

        $this->assertDatabaseCount('reactions', 1);

        $this->actingAs($this->bob)
            ->postJson("/api/messages/{$message->id}/react", ['emoji' => '😂'])
            ->assertOk()
            ->assertJsonPath('message.reactions', []);

        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_отметка_о_прочтении(): void
    {
        $message = Message::create(['user_id' => $this->alice->id, 'body' => 'Прочитай']);

        $this->assertNull($message->read_at);

        $this->actingAs($this->bob)->postJson('/api/read')->assertJsonPath('marked', 1);
        $this->assertNotNull($message->fresh()->read_at);

        // Своё сообщение прочитанным себя не делает
        $own = Message::create(['user_id' => $this->bob->id, 'body' => 'Моё']);
        $this->actingAs($this->bob)->postJson('/api/read')->assertJsonPath('marked', 0);
        $this->assertNull($own->fresh()->read_at);
    }

    public function test_индикатор_печати_виден_собеседнику(): void
    {
        $this->actingAs($this->bob)->postJson('/api/typing', ['state' => true])->assertOk();

        $this->actingAs($this->alice)
            ->getJson('/api/sync?last_id=0')
            ->assertJsonPath('peer.typing', true);

        $this->actingAs($this->bob)->postJson('/api/typing', ['state' => false]);

        $this->actingAs($this->alice)
            ->getJson('/api/sync?last_id=0')
            ->assertJsonPath('peer.typing', false);
    }

    public function test_поиск_по_тексту(): void
    {
        Message::create(['user_id' => $this->alice->id, 'body' => 'Встречаемся в парке']);
        Message::create(['user_id' => $this->bob->id, 'body' => 'Хорошо, до встречи']);

        $this->actingAs($this->alice)
            ->getJson('/api/search?q='.urlencode('парке'))
            ->assertOk()
            ->assertJsonCount(1, 'results');

        $this->actingAs($this->alice)
            ->getJson('/api/search?q='.urlencode('я'))
            ->assertJsonCount(0, 'results');
    }

    public function test_загрузка_файла_и_доступ_к_нему(): void
    {
        Storage::fake('local');

        $response = $this->actingAs($this->alice)->post('/api/messages', [
            'attachment' => UploadedFile::fake()->image('photo.jpg', 200, 100),
            'body' => 'Смотри',
        ]);

        $response->assertCreated()
            ->assertJsonPath('message.attachment.kind', 'image')
            ->assertJsonPath('message.attachment.name', 'photo.jpg');

        $path = Message::first()->attachment_path;
        Storage::disk('local')->assertExists($path);

        $this->actingAs($this->bob)->get('/attachments/'.Message::first()->id)->assertOk();
        $this->post('/logout');
        $this->get('/attachments/'.Message::first()->id)->assertRedirect('/login');
    }

    public function test_голосовое_сообщение_помечается_отдельным_типом(): void
    {
        Storage::fake('local');

        $this->actingAs($this->alice)->post('/api/messages', [
            'attachment' => UploadedFile::fake()->create('voice.webm', 20, 'audio/webm'),
            'voice' => 1,
            'duration' => 12,
        ])
            ->assertCreated()
            ->assertJsonPath('message.attachment.kind', 'voice')
            ->assertJsonPath('message.attachment.duration', 12);
    }

    public function test_закрепление_сообщения(): void
    {
        $message = Message::create(['user_id' => $this->bob->id, 'body' => 'Важное']);

        $this->actingAs($this->alice)
            ->postJson("/api/messages/{$message->id}/pin")
            ->assertOk()
            ->assertJsonPath('pinned.0.preview', 'Важное');

        $this->actingAs($this->alice)
            ->postJson("/api/messages/{$message->id}/pin")
            ->assertJsonPath('pinned', []);
    }

    public function test_очистка_истории(): void
    {
        Storage::fake('local');

        $message = Message::create(['user_id' => $this->alice->id, 'body' => 'Раз']);
        Reaction::create(['message_id' => $message->id, 'user_id' => $this->bob->id, 'emoji' => '👍']);

        $this->actingAs($this->bob)->deleteJson('/api/history')->assertOk();

        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_постраничная_загрузка_истории(): void
    {
        foreach (range(1, 60) as $i) {
            Message::create(['user_id' => $this->alice->id, 'body' => "Сообщение {$i}"]);
        }

        $page = $this->actingAs($this->bob)->getJson('/api/messages')->assertOk();
        $page->assertJsonCount(40, 'messages')->assertJsonPath('has_more', true);

        $oldest = $page->json('messages.0.id');
        $this->actingAs($this->bob)
            ->getJson("/api/messages?before_id={$oldest}")
            ->assertJsonCount(20, 'messages')
            ->assertJsonPath('has_more', false);
    }

    public function test_смена_кода_доступа(): void
    {
        $this->actingAs($this->alice)
            ->postJson('/api/profile/code', ['current' => '0000', 'code' => '7052'])
            ->assertStatus(422); // текущий код неверен

        $this->actingAs($this->alice)
            ->postJson('/api/profile/code', ['current' => '1394', 'code' => '8261'])
            ->assertStatus(422); // код занят собеседником

        $this->actingAs($this->alice)
            ->postJson('/api/profile/code', ['current' => '1394', 'code' => '7052'])
            ->assertOk();

        $this->post('/logout');
        $this->post('/login', ['code' => '7052']);
        $this->assertAuthenticatedAs($this->alice);
    }

    public function test_простые_коды_не_принимаются(): void
    {
        foreach (['0000', '7777', '1234', '3456', '9876', '0123'] as $weak) {
            $this->actingAs($this->alice)
                ->postJson('/api/profile/code', ['current' => '1394', 'code' => $weak])
                ->assertStatus(422);
        }

        $this->assertTrue(User::isWeakCode('1111'));
        $this->assertTrue(User::isWeakCode('2345'));
        $this->assertTrue(User::isWeakCode('5432'));
        $this->assertFalse(User::isWeakCode('7052'));
        $this->assertFalse(User::isWeakCode('1394'));
    }

    public function test_сгенерированный_код_не_бывает_простым(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $code = User::generateCode();
            $this->assertMatchesRegularExpression('/^\d{4}$/', $code);
            $this->assertFalse(User::isWeakCode($code), "Сгенерирован простой код: {$code}");
        }
    }

    public function test_страница_входа_не_раскрывает_участников(): void
    {
        $response = $this->get('/login')->assertOk();

        $response->assertDontSee('Алиса')->assertDontSee('Борис')->assertDontSee('alice');
        $response->assertSee('name="code"', false);
    }

    public function test_скрытый_статус_не_виден_собеседнику(): void
    {
        $this->bob->update(['hide_presence' => true]);
        $this->bob->forceFill(['last_seen_at' => now()])->saveQuietly();

        // Алиса не должна получить ни «в сети», ни время последнего захода
        $peer = $this->actingAs($this->alice)
            ->getJson('/api/sync?last_id=0')
            ->assertOk()
            ->assertJsonPath('peer.presence_hidden', true)
            ->json('peer');

        $this->assertArrayNotHasKey('online', $peer);
        $this->assertArrayNotHasKey('last_seen_at', $peer);
        $this->assertSame('Борис', $peer['name']);   // остальное на месте
    }

    public function test_скрытие_статуса_одностороннее(): void
    {
        $this->bob->update(['hide_presence' => true]);
        $this->alice->forceFill(['last_seen_at' => now()])->saveQuietly();

        // Борис по-прежнему видит статус Алисы
        $this->actingAs($this->bob)
            ->getJson('/api/sync?last_id=0')
            ->assertJsonPath('peer.presence_hidden', false)
            ->assertJsonPath('peer.online', true);
    }

    public function test_свой_статус_виден_себе_всегда(): void
    {
        $this->bob->update(['hide_presence' => true]);
        $this->bob->forceFill(['last_seen_at' => now()])->saveQuietly();

        $this->actingAs($this->bob)
            ->getJson('/api/sync?last_id=0')
            ->assertJsonPath('me.presence_hidden', false)
            ->assertJsonPath('me.online', true)
            ->assertJsonPath('me.hide_presence', true);
    }

    public function test_настройка_переключается_из_профиля(): void
    {
        $this->actingAs($this->bob)
            ->postJson('/api/profile', [
                'name' => 'Борис', 'color' => '#e0725c', 'hide_presence' => true,
            ])
            ->assertOk()
            ->assertJsonPath('user.hide_presence', true);

        $this->assertTrue($this->bob->fresh()->hide_presence);

        $this->actingAs($this->bob)
            ->postJson('/api/profile', [
                'name' => 'Борис', 'color' => '#e0725c', 'hide_presence' => false,
            ])
            ->assertOk();

        $this->assertFalse($this->bob->fresh()->hide_presence);
    }

    public function test_страница_чата_не_подставляет_статус_если_он_скрыт(): void
    {
        $this->bob->update(['hide_presence' => true]);

        $this->actingAs($this->alice)->get('/')->assertOk()
            ->assertDontSee('id="peer-status">не в сети', false);

        $this->bob->update(['hide_presence' => false]);

        $this->actingAs($this->alice)->get('/')->assertOk()
            ->assertSee('id="peer-status">не в сети', false);
    }

    public function test_команда_chat_privacy(): void
    {
        $this->artisan('chat:privacy bob on')->assertSuccessful();
        $this->assertTrue($this->bob->fresh()->hide_presence);

        $this->artisan('chat:privacy bob off')->assertSuccessful();
        $this->assertFalse($this->bob->fresh()->hide_presence);

        $this->artisan('chat:privacy никого on')->assertFailed();
    }

    public function test_обновление_профиля(): void
    {
        $this->actingAs($this->alice)
            ->postJson('/api/profile', ['name' => 'Алиса К.', 'bio' => 'Привет', 'color' => '#b57edc'])
            ->assertOk()
            ->assertJsonPath('user.name', 'Алиса К.')
            ->assertJsonPath('user.initials', 'АК');

        $this->actingAs($this->alice)
            ->postJson('/api/profile', ['name' => 'X', 'color' => 'красный'])
            ->assertStatus(422);
    }

    public function test_изменения_доезжают_через_sync(): void
    {
        $message = Message::create(['user_id' => $this->alice->id, 'body' => 'Первое']);
        $since = now()->subMinute()->toIso8601String();

        $this->actingAs($this->bob)->postJson("/api/messages/{$message->id}/react", ['emoji' => '🔥']);

        $this->actingAs($this->alice)
            ->getJson("/api/sync?last_id={$message->id}&since=".urlencode($since))
            ->assertOk()
            ->assertJsonPath('updated.0.reactions.0.emoji', '🔥');
    }

    public function test_битая_метка_времени_не_ломает_sync(): void
    {
        $this->actingAs($this->alice)
            ->getJson('/api/sync?last_id=0&since=не-дата')
            ->assertOk();
    }

    public function test_выход_из_чата(): void
    {
        $this->actingAs($this->alice)->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }
}
