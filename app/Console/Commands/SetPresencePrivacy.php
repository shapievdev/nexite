<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class SetPresencePrivacy extends Command
{
    protected $signature = 'chat:privacy {username? : Логин участника} {state? : on — скрывать, off — показывать}';

    protected $description = 'Скрыть или показать статус участника («в сети» и время последнего захода) для собеседника';

    public function handle(): int
    {
        $users = User::orderBy('id')->get();

        if ($users->isEmpty()) {
            $this->error('Участники не созданы. Запустите: php artisan db:seed');

            return self::FAILURE;
        }

        $username = $this->argument('username')
            ?: $this->choice('Чей статус настраиваем?', $users->pluck('username')->all());

        $user = $users->firstWhere('username', $username);

        if (! $user) {
            $this->error("Участник «{$username}» не найден. Доступны: ".$users->pluck('username')->join(', '));

            return self::FAILURE;
        }

        $state = strtolower((string) ($this->argument('state')
            ?: $this->choice('Скрывать статус от собеседника?', ['on', 'off'], $user->hide_presence ? 0 : 1)));

        if (! in_array($state, ['on', 'off'], true)) {
            $this->error('Укажите on или off.');

            return self::FAILURE;
        }

        $user->update(['hide_presence' => $state === 'on']);

        $peer = $user->peer();

        $this->info($state === 'on'
            ? "{$user->name}: статус скрыт — ".($peer?->name ?? 'собеседник')
                .' больше не увидит ни «в сети», ни время последнего захода.'
            : "{$user->name}: статус виден собеседнику как обычно.");

        $this->line('Настройка односторонняя: сам '.$user->name.' статус собеседника видит по-прежнему.');

        return self::SUCCESS;
    }
}
