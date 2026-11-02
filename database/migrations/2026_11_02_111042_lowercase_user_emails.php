<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Store every sign-in email trimmed and lowercase, the form Fortify looks
     * it up in. An account saved as Ana@… could not sign in at all.
     *
     * Two accounts that differ only in case or spacing are one person with two
     * logins. Which to keep is a decision for a person, not a migration, so it
     * stops and names them rather than merging or dropping either.
     */
    public function up(): void
    {
        $clashes = DB::table('users')
            ->selectRaw('lower(trim(email)) as normalised')
            ->groupByRaw('lower(trim(email))')
            ->havingRaw('count(*) > 1')
            ->pluck('normalised');

        if ($clashes->isNotEmpty()) {
            throw new RuntimeException(
                'These emails are held by more than one account once lowercased: '.$clashes->implode(', ')
                .'. Close or rename the extra accounts, then migrate again.',
            );
        }

        DB::table('users')
            ->whereRaw('email <> lower(trim(email))')
            ->update(['email' => DB::raw('lower(trim(email))')]);
    }

    /**
     * Nothing to undo: the original capitals are not kept, and the lowercase
     * form is the one sign-in needs anyway.
     */
    public function down(): void
    {
        //
    }
};
