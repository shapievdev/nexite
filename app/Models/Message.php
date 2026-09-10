<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Message extends Model
{
    use HasFactory, SoftDeletes;

    /** Сколько минут после отправки можно редактировать своё сообщение */
    public const EDIT_WINDOW_MINUTES = 60 * 24;

    protected $fillable = [
        'user_id',
        'reply_to_id',
        'body',
        'attachment_path',
        'attachment_name',
        'attachment_mime',
        'attachment_size',
        'attachment_kind',
        'attachment_duration',
        'attachment_width',
        'attachment_height',
        'edited_at',
        'read_at',
        'pinned',
    ];

    protected $attributes = [
        'pinned' => false,
    ];

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
            'read_at' => 'datetime',
            'pinned' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id')->withTrashed();
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(Reaction::class);
    }

    public function isEditable(): bool
    {
        return $this->created_at?->gt(now()->subMinutes(self::EDIT_WINDOW_MINUTES)) ?? false;
    }

    public function toArray(): array
    {
        if ($this->trashed()) {
            return [
                'id' => $this->id,
                'user_id' => $this->user_id,
                'deleted' => true,
                'created_at' => $this->created_at?->toIso8601String(),
                'updated_at' => $this->updated_at?->toIso8601String(),
            ];
        }

        $reactions = [];
        foreach ($this->reactions as $reaction) {
            $reactions[$reaction->emoji][] = $reaction->user_id;
        }

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'deleted' => false,
            'body' => $this->body,
            'pinned' => (bool) $this->pinned,
            'reply_to' => $this->relationLoaded('replyTo') && $this->replyTo
                ? [
                    'id' => $this->replyTo->id,
                    'user_id' => $this->replyTo->user_id,
                    'deleted' => $this->replyTo->trashed(),
                    'preview' => $this->replyTo->preview(),
                ]
                : null,
            'attachment' => $this->attachment_path ? [
                'kind' => $this->attachment_kind,
                'name' => $this->attachment_name,
                'mime' => $this->attachment_mime,
                'size' => (int) $this->attachment_size,
                'duration' => $this->attachment_duration,
                'width' => $this->attachment_width,
                'height' => $this->attachment_height,
                'url' => route('attachment', $this),
            ] : null,
            'reactions' => array_map(
                static fn ($emoji, $users) => ['emoji' => $emoji, 'users' => $users],
                array_keys($reactions),
                $reactions
            ),
            'edited_at' => $this->edited_at?->toIso8601String(),
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /** Короткое текстовое описание — для ответов и закрепа. */
    public function preview(int $limit = 120): string
    {
        if ($this->trashed()) {
            return 'Сообщение удалено';
        }

        if (filled($this->body)) {
            return mb_strimwidth(trim(preg_replace('/\s+/u', ' ', $this->body)), 0, $limit, '…');
        }

        return match ($this->attachment_kind) {
            'image' => '📷 Фото',
            'video' => '🎬 Видео',
            'voice' => '🎤 Голосовое сообщение',
            'audio' => '🎵 Аудио',
            'file' => '📎 '.$this->attachment_name,
            default => 'Сообщение',
        };
    }
}
