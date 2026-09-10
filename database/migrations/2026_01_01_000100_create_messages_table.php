<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reply_to_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->text('body')->nullable();

            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime')->nullable();
            $table->unsignedBigInteger('attachment_size')->nullable();
            $table->string('attachment_kind', 20)->nullable(); // image | video | audio | voice | file
            $table->unsignedInteger('attachment_duration')->nullable(); // сек, для голосовых
            $table->unsignedInteger('attachment_width')->nullable();
            $table->unsignedInteger('attachment_height')->nullable();

            $table->timestamp('edited_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->boolean('pinned')->default(false);

            $table->softDeletes();
            $table->timestamps();

            $table->index('created_at');
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
