<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Двое участников чата. Коды выдаются случайные — печатаются один раз. */
    public const PARTICIPANTS = [
        ['username' => 'alice', 'name' => 'Алиса', 'color' => '#5b8def'],
        ['username' => 'bob', 'name' => 'Борис', 'color' => '#e0725c'],
    ];

    public function run(): void
    {
        $issued = [];

        foreach (self::PARTICIPANTS as $participant) {
            $user = User::firstWhere('username', $participant['username']);

            if ($user) {
                $user->update([
                    'name' => $participant['name'],
                    'color' => $participant['color'],
                ]);

                $this->command?->line("  {$participant['name']} — код не изменён");

                continue;
            }

            $code = User::generateCode();

            User::create($participant + ['access_code' => $code]);
            $issued[$participant['name']] = $code;
        }

        if ($issued === []) {
            return;
        }

        $this->command?->newLine();
        $this->command?->info('  Коды доступа (сохраните — восстановить нельзя):');
        $this->command?->newLine();

        foreach ($issued as $name => $code) {
            $this->command?->line("      {$name}  →  <fg=yellow;options=bold>{$code}</>");
        }

        $this->command?->newLine();
        $this->command?->line('  Сменить: php artisan chat:code <логин> <код>');
        $this->command?->newLine();
    }
}
