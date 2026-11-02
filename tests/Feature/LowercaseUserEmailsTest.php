<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class LowercaseUserEmailsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The migration under test, run again over rows written after it.
     */
    protected function migration(): Migration
    {
        return require database_path('migrations/2026_11_02_111042_lowercase_user_emails.php');
    }

    public function test_it_stores_existing_emails_trimmed_and_lowercase(): void
    {
        $ana = User::factory()->create(['email' => 'Ana@PurpleYam.Store']);
        $bob = User::factory()->create(['email' => ' bob@purpleyam.store ']);
        $cy = User::factory()->create(['email' => 'cy@purpleyam.store']);

        $this->migration()->up();

        $this->assertSame('ana@purpleyam.store', $ana->fresh()->email);
        $this->assertSame('bob@purpleyam.store', $bob->fresh()->email);
        $this->assertSame('cy@purpleyam.store', $cy->fresh()->email);
    }

    public function test_it_stops_and_changes_nothing_when_two_accounts_would_clash(): void
    {
        User::factory()->create(['email' => 'ana@purpleyam.store']);
        User::factory()->create(['email' => 'Ana@PurpleYam.Store']);
        $bob = User::factory()->create(['email' => 'Bob@PurpleYam.Store']);

        try {
            $this->migration()->up();
            $this->fail('The migration should have refused to merge two accounts.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ana@purpleyam.store', $e->getMessage());
        }

        $this->assertDatabaseHas('users', ['email' => 'Ana@PurpleYam.Store']);
        $this->assertSame('Bob@PurpleYam.Store', $bob->fresh()->email);
    }
}
