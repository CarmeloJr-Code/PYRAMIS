<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outlets', function (Blueprint $table): void {
            // BR-003 and BR-004 make the main branch a distinct role, not
            // another outlet: it runs production and everything else receives
            // finished goods from it. Phase 7's workflow starts at "Main Branch
            // Finished Stock", so the row has to be identifiable.
            //
            // It stays an outlet rather than a table of its own, because it is
            // also a pickup and sales location and is already referenced that
            // way by orders and sales.
            $table->boolean('is_main_branch')->default(false)->after('is_active');
        });

        // Whatever the business already has is the main branch — in practice the
        // single seeded outlet. Oldest wins, so a later one cannot claim it.
        $firstOutletId = DB::table('outlets')->orderBy('id')->value('id');

        if ($firstOutletId !== null) {
            DB::table('outlets')->where('id', $firstOutletId)->update(['is_main_branch' => true]);
        }

        // Exactly one row may hold the flag. A partial unique index says that in
        // the schema rather than in hope; SQLite cannot express it, so CI is the
        // gate, as with the other check constraints.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('create unique index outlets_single_main_branch on outlets (is_main_branch) where is_main_branch');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('drop index if exists outlets_single_main_branch');
        }

        Schema::table('outlets', function (Blueprint $table): void {
            $table->dropColumn('is_main_branch');
        });
    }
};
