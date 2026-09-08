<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // Text, and only text. BR-010 and FR-09 exclude voice, video, photo
            // messaging and any external platform, so there is no attachment
            // column here and no place for one to be added by accident.
            $table->text('body');

            $table->timestamps();

            // A thread is read oldest-first, and the list needs each
            // conversation's latest message.
            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
