<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class SetAccessCode extends Command
{
    protected $signature = 'chat:code
        {username? : Логин участника}
        {code? : Новый 4-значный код}
        {--random : Сгенерировать случайный код}';

    protected $description = 'Изменить код доступа участника чата';

    public function handle(): int
    {
        $users = User::orderBy('id')->get();

        if ($users->isEmpty()) {
            $this->error('Участники не созданы. Запустите: php artisan db:seed');

            return self::FAILURE;
        }

        $username = $this->argument('username')
            ?: $this->choice('Кому меняем код?', $users->pluck('username')->all());

        $user = $users->firstWhere('username', $username);

        if (! $user) {
            $this->error("Участник «{$username}» не найден. Доступны: ".$users->pluck('username')->join(', '));

            return self::FAILURE;
        }

        $code = $this->option('random')
            ? User::generateCode()
            : (string) ($this->argument('code') ?: $this->ask('Новый код (4 цифры)'));

        if (! preg_match('/^\d{4}$/', $code)) {
            $this->error('Код должен состоять ровно из 4 цифр.');

            return self::FAILURE;
        }

        if (User::isWeakCode($code)) {
            $this->error('Слишком простой код: не используйте одинаковые или идущие подряд цифры.');

            return self::FAILURE;
        }

        if ($peer = $user->peer()) {
            if (\Illuminate\Support\Facades\Hash::check($code, $peer->access_code)) {
                $this->error("Этот код уже занят участником {$peer->name}.");

                return self::FAILURE;
            }
        }

        $user->update(['access_code' => $code]);

        $this->info("Код участника {$user->name} ({$user->username}) обновлён: {$code}");

        return self::SUCCESS;
    }
}
