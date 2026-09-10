<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateVapidKeys extends Command
{
    protected $signature = 'chat:vapid {--force : Перезаписать существующие ключи}';

    protected $description = 'Создать VAPID-ключи для push-уведомлений и записать их в .env';

    public function handle(): int
    {
        $env = base_path('.env');

        if (! is_file($env)) {
            $this->error('Файл .env не найден.');

            return self::FAILURE;
        }

        $contents = file_get_contents($env);
        $hasKeys = preg_match('/^VAPID_PUBLIC_KEY=.+$/m', $contents);

        if ($hasKeys && ! $this->option('force')) {
            $this->warn('Ключи уже заданы. Перезаписать: php artisan chat:vapid --force');
            $this->line('Внимание: после смены ключей все устройства придётся подписать заново.');

            return self::SUCCESS;
        }

        $keys = VAPID::createVapidKeys();

        $contents = preg_replace('/^VAPID_(SUBJECT|PUBLIC_KEY|PRIVATE_KEY)=.*$\n?/m', '', $contents);
        $contents = rtrim($contents)."\n\nVAPID_SUBJECT=mailto:chat@localhost\n"
            ."VAPID_PUBLIC_KEY={$keys['publicKey']}\n"
            ."VAPID_PRIVATE_KEY={$keys['privateKey']}\n";

        file_put_contents($env, $contents);
        $this->call('config:clear');

        $this->info('VAPID-ключи записаны в .env.');
        $this->line('Публичный ключ: '.$keys['publicKey']);

        return self::SUCCESS;
    }
}
