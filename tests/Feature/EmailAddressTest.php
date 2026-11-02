<?php

namespace Tests\Feature;

use App\Rules\EmailAddress;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class EmailAddressTest extends TestCase
{
    /**
     * Addresses no entry point should take. Shared with each form that asks
     * for an email, so they are all held to the same list.
     *
     * @return array<string, array{string}>
     */
    public static function notAnEmailProvider(): array
    {
        return [
            'not an address' => ['not-an-email'],
            'a space inside' => ['ana reyes@gmail.com'],
            'a bare host' => ['ana@localhost'],
            'no top-level domain' => ['ana@purpleyam'],
            'a quoted name' => ['"ana reyes"@gmail.com'],
            'a comment' => ['ana(reyes)@gmail.com'],
            'two dots in a row' => ['ana..reyes@gmail.com'],
            // Well formed, so only the DNS check can refuse it: a reserved
            // name that never resolves.
            'a domain that is not in DNS' => ['ana@purpleyam.invalid'],
        ];
    }

    #[TestWith(['ana@gmail.com'])]
    #[TestWith(['ana.reyes+cakes@purpleyam.store'])]
    #[TestWith(['niño@correo.ph'])]
    public function test_a_working_address_passes(string $email): void
    {
        $this->assertTrue(Validator::make(['email' => $email], ['email' => new EmailAddress])->passes());
    }

    #[DataProvider('notAnEmailProvider')]
    public function test_anything_else_fails_and_says_what_it_wants(string $email): void
    {
        $validator = Validator::make(['email' => $email], ['email' => new EmailAddress]);

        $this->assertTrue($validator->fails());
        $this->assertSame('Please enter a working email address, like ana@gmail.com.', $validator->errors()->first('email'));
    }
}
