<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function headerProvider(): array
    {
        return [
            'a response is only ever the type it says it is' => ['X-Content-Type-Options', 'nosniff'],
            'nothing here may be framed' => ['X-Frame-Options', 'DENY'],
            'an order reference does not travel to another site' => ['Referrer-Policy', 'strict-origin-when-cross-origin'],
        ];
    }

    #[DataProvider('headerProvider')]
    public function test_the_storefront_carries_the_header(string $header, string $value): void
    {
        $this->get(route('home'))->assertHeader($header, $value);
    }

    #[DataProvider('headerProvider')]
    public function test_the_workspace_carries_the_header(string $header, string $value): void
    {
        $this->actingAs(User::factory()->administrator()->create())
            ->get(route('employee.dashboard'))
            ->assertHeader($header, $value);
    }

    #[DataProvider('headerProvider')]
    public function test_a_refusal_carries_the_header_too(string $header, string $value): void
    {
        // The page most worth framing is the one that says no, so the headers
        // have to survive the exception handler rather than only the happy path.
        $this->actingAs(User::factory()->baker()->create())
            ->get(route('employee.workforce'))
            ->assertForbidden()
            ->assertHeader($header, $value);
    }

    #[DataProvider('headerProvider')]
    public function test_a_page_that_does_not_exist_carries_the_header_too(string $header, string $value): void
    {
        $this->get('/orders/PY-NOTAREFERENCE')
            ->assertNotFound()
            ->assertHeader($header, $value);
    }
}
