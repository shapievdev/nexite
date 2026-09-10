<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * «Скрывать, когда я в сети»: собеседник перестаёт видеть и отметку
     * «в сети», и время последнего захода. Настройка односторонняя —
     * тот, кто её включил, статус собеседника видит как обычно.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('hide_presence')->default(false)->after('bio');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('hide_presence');
        });
    }
};
