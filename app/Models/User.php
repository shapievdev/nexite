<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /** Через сколько секунд без активности считаем собеседника оффлайн */
    public const ONLINE_WINDOW = 25;

    /**
     * Сколько секунд отметка «окно чата открыто на экране» считается свежей.
     * Пока она свежая, push не отправляем — человек и так всё видит.
     */
    public const ACTIVE_WINDOW = 12;

    /** Сколько секунд «живёт» отметка «печатает…» */
    public const TYPING_WINDOW = 6;

    protected $fillable = [
        'name',
        'username',
        'access_code',
        'color',
        'avatar_path',
        'bio',
        'hide_presence',
        'last_seen_at',
        'active_at',
        'typing_at',
    ];

    protected $hidden = [
        'access_code',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'access_code' => 'hashed',
            'hide_presence' => 'boolean',
            'last_seen_at' => 'datetime',
            'active_at' => 'datetime',
            'typing_at' => 'datetime',
        ];
    }

    /**
     * Код из четырёх цифр угадать несложно, поэтому очевидные комбинации
     * (0000, 1111, 1234, 4321 и т.п.) не допускаем.
     */
    public static function isWeakCode(string $code): bool
    {
        if (! preg_match('/^\d{4}$/', $code)) {
            return true;
        }

        if (preg_match('/^(\d)\1{3}$/', $code)) {
            return true;                                  // одна цифра подряд
        }

        $digits = array_map('intval', str_split($code));
        $up = $down = true;

        for ($i = 1; $i < 4; $i++) {
            $up = $up && $digits[$i] === ($digits[$i - 1] + 1) % 10;
            $down = $down && $digits[$i] === ($digits[$i - 1] + 9) % 10;
        }

        return $up || $down;                              // 1234 / 4321 и подобные
    }

    /** Случайный код, пригодный для выдачи участнику. */
    public static function generateCode(): string
    {
        do {
            $code = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        } while (self::isWeakCode($code));

        return $code;
    }

    /** Пароля в привычном смысле нет — «паролем» служит хеш кода доступа. */
    public function getAuthPassword(): string
    {
        return $this->access_code;
    }

    public function getAuthPasswordName(): string
    {
        return 'access_code';
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subSeconds(self::ONLINE_WINDOW));
    }

    /** Чат прямо сейчас открыт на экране (вкладка видима). */
    public function isActive(): bool
    {
        return $this->active_at !== null
            && $this->active_at->gt(now()->subSeconds(self::ACTIVE_WINDOW));
    }

    public function isTyping(): bool
    {
        return $this->typing_at !== null
            && $this->typing_at->gt(now()->subSeconds(self::TYPING_WINDOW));
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/u', trim($this->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return mb_strtoupper(implode('', array_map(
            static fn ($p) => mb_substr($p, 0, 1),
            array_slice($parts, 0, 2)
        ))) ?: mb_strtoupper(mb_substr($this->username, 0, 1));
    }

    /** Собеседник — единственный другой пользователь чата. */
    public function peer(): ?self
    {
        return static::whereKeyNot($this->getKey())->orderBy('id')->first();
    }

    /** Скрывает ли этот участник своё присутствие от собеседника. */
    public function hidesPresenceFrom(?self $viewer): bool
    {
        return $this->hide_presence
            && $viewer !== null
            && $viewer->getKey() !== $this->getKey();
    }

    /**
     * @param  self|null  $viewer  кто смотрит; для него скрываем присутствие,
     *                             если владелец профиля так настроил
     */
    public function toPublicArray(?self $viewer = null): array
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'color' => $this->color,
            'initials' => $this->initials(),
            'avatar_url' => $this->avatar_path ? route('avatar', $this) : null,
            'bio' => $this->bio,
            'typing' => $this->isTyping(),
        ];

        if ($this->hidesPresenceFrom($viewer)) {
            // Ни «в сети», ни времени последнего захода — полей просто нет.
            return $data + ['presence_hidden' => true];
        }

        return $data + [
            'presence_hidden' => false,
            'online' => $this->isOnline(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'hide_presence' => $this->hide_presence,
        ];
    }
}
