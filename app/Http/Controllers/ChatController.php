<?php

namespace App\Http\Controllers;

use App\Jobs\NotifyPeerOfMessage;
use App\Models\Message;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChatController extends Controller
{
    private const PAGE_SIZE = 40;

    private const MAX_UPLOAD_KB = 25600; // 25 МБ

    public function index(): View
    {
        $me = Auth::user();
        $peer = $me->peer();

        return view('chat', [
            'me' => $me,
            'peer' => $peer,
        ]);
    }

    /**
     * История сообщений.
     * ?before_id=   — страница вверх (более старые)
     * ?around_id=   — окно вокруг сообщения (переход из поиска)
     * без параметров — последняя страница
     */
    public function messages(Request $request): JsonResponse
    {
        $me = Auth::user();

        if ($aroundId = $request->integer('around_id')) {
            $before = $this->baseQuery()->where('id', '<', $aroundId)
                ->orderByDesc('id')->limit(20)->get()->reverse();
            $after = $this->baseQuery()->where('id', '>=', $aroundId)
                ->orderBy('id')->limit(20)->get();
            $items = $before->concat($after)->values();
        } elseif ($beforeId = $request->integer('before_id')) {
            $items = $this->baseQuery()->where('id', '<', $beforeId)
                ->orderByDesc('id')->limit(self::PAGE_SIZE)->get()->reverse()->values();
        } else {
            $items = $this->baseQuery()->orderByDesc('id')
                ->limit(self::PAGE_SIZE)->get()->reverse()->values();
        }

        $oldestId = $items->first()?->id;
        $hasMore = $oldestId
            ? Message::withTrashed()->where('id', '<', $oldestId)->exists()
            : false;

        return response()->json([
            'messages' => $items->map->toArray(),
            'has_more' => $hasMore,
            'first_unread_id' => $this->firstUnreadId($me),
            'total' => Message::count(),
        ]);
    }

    /** Лёгкий поллинг: новые сообщения + изменения + статус собеседника. */
    public function sync(Request $request): JsonResponse
    {
        $me = Auth::user();
        $peer = $me->peer();

        $this->markActive($me, $request->boolean('visible'));

        $lastId = $request->integer('last_id');
        $since = $this->parseSince($request->query('since'));

        $new = $this->baseQuery()->where('id', '>', $lastId)->orderBy('id')->limit(100)->get();

        $updated = $this->baseQuery()
            ->where('id', '<=', $lastId)
            ->where('updated_at', '>=', $since)
            ->orderBy('id')
            ->limit(200)
            ->get();

        return response()->json([
            'now' => now()->toIso8601String(),
            'messages' => $new->map->toArray(),
            'updated' => $updated->map->toArray(),
            'peer' => $peer?->toPublicArray($me),
            'me' => $me->toPublicArray($me),
            'pinned' => $this->pinnedPayload(),
            'total' => Message::count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'body' => ['nullable', 'string', 'max:8000'],
            'reply_to_id' => ['nullable', 'integer', 'exists:messages,id'],
            'attachment' => ['nullable', 'file', 'max:'.self::MAX_UPLOAD_KB],
            'voice' => ['nullable', 'boolean'],
            'duration' => ['nullable', 'integer', 'min:0', 'max:36000'],
            'width' => ['nullable', 'integer', 'min:0'],
            'height' => ['nullable', 'integer', 'min:0'],
        ], [
            'attachment.max' => 'Файл слишком большой (максимум 25 МБ).',
        ]);

        $body = trim((string) $request->input('body'));
        $file = $request->file('attachment');

        if ($body === '' && ! $file) {
            return response()->json(['message' => 'Пустое сообщение.'], 422);
        }

        $payload = [
            'user_id' => Auth::id(),
            'reply_to_id' => $request->integer('reply_to_id') ?: null,
            'body' => $body !== '' ? $body : null,
        ];

        if ($file) {
            $ext = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'bin');
            $name = Str::uuid()->toString().'.'.preg_replace('/[^a-z0-9]/', '', $ext);
            $path = $file->storeAs('chat', $name, 'local');

            $mime = $file->getClientMimeType() ?: 'application/octet-stream';
            $kind = $this->detectKind($mime, $request->boolean('voice'));

            $payload += [
                'attachment_path' => $path,
                'attachment_name' => Str::limit($file->getClientOriginalName() ?: 'file', 180, ''),
                'attachment_mime' => $mime,
                'attachment_size' => $file->getSize(),
                'attachment_kind' => $kind,
                'attachment_duration' => $request->integer('duration') ?: null,
                'attachment_width' => $request->integer('width') ?: null,
                'attachment_height' => $request->integer('height') ?: null,
            ];

            if ($kind === 'image' && $payload['attachment_width'] === null) {
                $size = @getimagesize($file->getRealPath() ?: '');
                if ($size) {
                    $payload['attachment_width'] = $size[0];
                    $payload['attachment_height'] = $size[1];
                }
            }
        }

        $message = Message::create($payload);
        $message->load(['replyTo', 'reactions']);

        // Push уходит уже после ответа — отправитель не ждёт поход к push-сервису.
        NotifyPeerOfMessage::dispatchAfterResponse($message->id);

        return response()->json(['message' => $message->toArray()], 201);
    }

    public function update(Request $request, Message $message): JsonResponse
    {
        $this->authorizeOwner($message);

        if (! $message->isEditable()) {
            return response()->json(['message' => 'Срок редактирования истёк.'], 422);
        }

        $data = $request->validate([
            'body' => ['required', 'string', 'max:8000'],
        ]);

        $body = trim($data['body']);

        if ($body === '') {
            return response()->json(['message' => 'Текст не может быть пустым.'], 422);
        }

        $message->update(['body' => $body, 'edited_at' => now()]);
        $message->load(['replyTo', 'reactions']);

        return response()->json(['message' => $message->toArray()]);
    }

    public function destroy(Message $message): JsonResponse
    {
        $this->authorizeOwner($message);

        if ($message->attachment_path) {
            Storage::disk('local')->delete($message->attachment_path);
            $message->forceFill(['attachment_path' => null])->saveQuietly();
        }

        $message->pinned = false;
        $message->save();
        $message->delete();

        return response()->json(['ok' => true, 'id' => $message->id]);
    }

    public function react(Request $request, Message $message): JsonResponse
    {
        $data = $request->validate([
            'emoji' => ['required', 'string', 'max:16'],
        ]);

        $existing = Reaction::where('message_id', $message->id)
            ->where('user_id', Auth::id())
            ->where('emoji', $data['emoji'])
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            Reaction::create([
                'message_id' => $message->id,
                'user_id' => Auth::id(),
                'emoji' => $data['emoji'],
            ]);
        }

        $message->touch(); // чтобы изменение доехало до собеседника через sync
        $message->load(['replyTo', 'reactions']);

        return response()->json(['message' => $message->toArray()]);
    }

    public function pin(Message $message): JsonResponse
    {
        $message->update(['pinned' => ! $message->pinned]);

        return response()->json(['pinned' => $this->pinnedPayload(), 'message' => $message->fresh()->load(['replyTo', 'reactions'])->toArray()]);
    }

    public function read(): JsonResponse
    {
        $me = Auth::user();

        $count = Message::whereNot('user_id', $me->id)
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);

        return response()->json(['marked' => $count]);
    }

    /**
     * Явный сигнал «окно чата свернули / развернули».
     * Вызывается при visibilitychange, в том числе через sendBeacon,
     * чтобы push-уведомления возобновились сразу, а не через таймаут.
     */
    public function presence(Request $request): JsonResponse
    {
        $this->markActive(Auth::user(), $request->boolean('visible'));

        return response()->json(['ok' => true]);
    }

    private function markActive(User $user, bool $visible): void
    {
        if ($visible) {
            $user->forceFill(['active_at' => now()])->saveQuietly();

            return;
        }

        if ($user->active_at !== null) {
            $user->forceFill(['active_at' => null, 'typing_at' => null])->saveQuietly();
        }
    }

    public function typing(Request $request): JsonResponse
    {
        Auth::user()->forceFill([
            'typing_at' => $request->boolean('state', true) ? now() : null,
        ])->saveQuietly();

        return response()->json(['ok' => true]);
    }

    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q'));

        if (mb_strlen($q) < 2) {
            return response()->json(['results' => []]);
        }

        $results = $this->baseQuery()
            ->where('body', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%')
            ->orderByDesc('id')
            ->limit(60)
            ->get();

        return response()->json([
            'results' => $results->map(fn (Message $m) => [
                'id' => $m->id,
                'user_id' => $m->user_id,
                'body' => $m->body,
                'created_at' => $m->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function attachment(Request $request, Message $message): BinaryFileResponse|StreamedResponse
    {
        abort_if($message->attachment_path === null, 404);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($message->attachment_path), 404);

        $headers = ['Content-Type' => $message->attachment_mime ?: 'application/octet-stream'];

        if ($request->boolean('download')) {
            return $disk->download($message->attachment_path, $message->attachment_name, $headers);
        }

        return response()->file($disk->path($message->attachment_path), $headers);
    }

    public function avatar(User $user): BinaryFileResponse
    {
        abort_if($user->avatar_path === null, 404);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($user->avatar_path), 404);

        return response()->file($disk->path($user->avatar_path));
    }

    /** Полная очистка переписки (обоюдная — чат один на двоих). */
    public function clear(): JsonResponse
    {
        foreach (Message::withTrashed()->whereNotNull('attachment_path')->get() as $message) {
            Storage::disk('local')->delete($message->attachment_path);
        }

        DB::table('reactions')->delete();
        DB::table('messages')->delete();

        return response()->json(['ok' => true]);
    }

    /** Метка последней синхронизации клиента; при любой некорректности — минута назад. */
    private function parseSince(mixed $since): Carbon
    {
        if (! is_string($since) || $since === '') {
            return now()->subMinute();
        }

        try {
            return Carbon::parse($since)->subSeconds(2);
        } catch (\Throwable) {
            return now()->subMinute();
        }
    }

    private function baseQuery()
    {
        return Message::withTrashed()->with(['replyTo', 'reactions']);
    }

    private function firstUnreadId(User $me): ?int
    {
        return Message::whereNot('user_id', $me->id)
            ->whereNull('read_at')
            ->orderBy('id')
            ->value('id');
    }

    private function pinnedPayload(): array
    {
        return Message::where('pinned', true)
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (Message $m) => [
                'id' => $m->id,
                'user_id' => $m->user_id,
                'preview' => $m->preview(90),
            ])
            ->all();
    }

    private function detectKind(string $mime, bool $voice): string
    {
        return match (true) {
            $voice => 'voice',
            str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml' => 'image',
            str_starts_with($mime, 'video/') => 'video',
            str_starts_with($mime, 'audio/') => 'audio',
            default => 'file',
        };
    }

    private function authorizeOwner(Message $message): void
    {
        abort_unless($message->user_id === Auth::id(), 403, 'Это не ваше сообщение.');
    }
}
