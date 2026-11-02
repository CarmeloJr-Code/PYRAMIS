<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Feature\EmailAddressTest;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $this->actingAs($user = User::factory()->create());

        $this->get(route('profile.edit'))->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.profile')
            ->set('name', 'Test User')
            ->set('email', 'test@example.com')
            ->call('updateProfileInformation');

        $response->assertHasNoErrors();

        $user->refresh();

        $this->assertEquals('Test User', $user->name);
        $this->assertEquals('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_the_email_is_stored_trimmed_and_lowercase(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::settings.profile')
            ->set('email', ' Ana.Reyes@PurpleYam.Store ')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $this->assertSame('ana.reyes@purpleyam.store', $user->refresh()->email);
    }

    public function test_an_email_another_account_holds_is_refused_whatever_its_case(): void
    {
        User::factory()->create(['email' => 'taken@purpleyam.store']);
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::settings.profile')
            ->set('email', 'Taken@PurpleYam.Store')
            ->call('updateProfileInformation')
            ->assertHasErrors(['email' => 'unique']);

        $this->assertNotSame('taken@purpleyam.store', $user->refresh()->email);
    }

    #[DataProviderExternal(EmailAddressTest::class, 'notAnEmailProvider')]
    public function test_an_email_that_is_not_one_is_refused(string $email): void
    {
        $user = User::factory()->create();
        $before = $user->email;

        Livewire::actingAs($user)
            ->test('pages::settings.profile')
            ->set('email', $email)
            ->call('updateProfileInformation')
            ->assertHasErrors('email')
            ->assertSee('Please enter a working email address, like ana@gmail.com.');

        $this->assertSame($before, $user->refresh()->email);
    }

    #[TestWith([''])]
    #[TestWith(['   '])]
    public function test_the_email_cannot_be_cleared(string $email): void
    {
        $user = User::factory()->create();
        $before = $user->email;

        Livewire::actingAs($user)
            ->test('pages::settings.profile')
            ->set('email', $email)
            ->call('updateProfileInformation')
            ->assertHasErrors(['email' => 'required']);

        $this->assertSame($before, $user->refresh()->email);
    }

    public function test_an_email_over_the_limit_is_refused(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test('pages::settings.profile')
            ->set('email', str_repeat('a', 250).'@purpleyam.store')
            ->call('updateProfileInformation')
            ->assertHasErrors(['email' => 'max'])
            ->assertSeeHtml('maxlength="255"');
    }

    public function test_email_verification_status_is_unchanged_when_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.profile')
            ->set('name', 'Test User')
            ->set('email', $user->email)
            ->call('updateProfileInformation');

        $response->assertHasNoErrors();

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'password')
            ->call('deleteUser');

        $response
            ->assertHasNoErrors()
            ->assertRedirect('/');

        $this->assertNull($user->fresh());
        $this->assertFalse(auth()->check());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = Livewire::test('pages::settings.delete-user-modal')
            ->set('password', 'wrong-password')
            ->call('deleteUser');

        $response->assertHasErrors(['password']);

        $this->assertNotNull($user->fresh());
    }
}
