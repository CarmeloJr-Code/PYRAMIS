<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_participants', function (Blueprint $table): void {
            $table->id();

            // Cascading: a participant row describes a conversation and means
            // nothing without it.
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();

            // Restricted, like everywhere else a user is referenced: employees
            // are closed, not deleted, and their words stay attributable.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            // How far this participant has read, by message rather than by
            // clock: two messages can land in the same second, and a timestamp
            // comparison would then hide one of them. Null means they have not
            // read any of it.
            //
            // Nulled rather than restricted on delete: losing the read mark is
            // harmless — the thread simply reads as unread again.
            $table->foreignId('last_read_message_id')->nullable()->constrained('messages')->nullOnDelete();

            $table->timestamps();

            // One place each in a conversation.
            $table->unique(['conversation_id', 'user_id']);

            // "My conversations" is the other way this table is read.
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_participants');
    }
};
