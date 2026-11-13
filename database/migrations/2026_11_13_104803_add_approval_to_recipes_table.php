<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table): void {
            // Where the recipe stands with the Administrator — see
            // App\Enums\RecipeStatus (BR-016). Every recipe written before
            // approval existed is already being baked from, so the default
            // keeps them approved and nothing stops being producible on deploy.
            $table->string('status')->default('approved')->after('notes');

            // Who last saved it, and when. Null on recipes older than this
            // column. Restricting like every other "who did it" link: an
            // employee is deactivated, not deleted.
            $table->foreignId('submitted_by')->nullable()->after('status')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable()->after('submitted_by');

            // The Administrator's decision on the version last saved, and the
            // reason given when it was sent back.
            $table->foreignId('reviewed_by')->nullable()->after('submitted_at')->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');
            $table->text('review_note')->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('recipes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['status', 'submitted_at', 'reviewed_at', 'review_note']);
        });
    }
};
