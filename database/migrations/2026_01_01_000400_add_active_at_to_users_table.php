<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * last_seen_at говорит лишь о том, что приложение опрашивает сервер —
     * установленный PWA делает это и лёжа в кармане. active_at отмечает,
     * что окно чата действительно открыто на экране: только в этом случае
     * push-уведомление лишнее.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('active_at')->nullable()->after('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('active_at');
        });
    }
};
