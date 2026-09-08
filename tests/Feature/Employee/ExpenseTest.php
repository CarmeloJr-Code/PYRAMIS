<?php

namespace Tests\Feature\Employee;

use App\Enums\UserRole;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExpenseTest extends TestCase
{
    use RefreshDatabase;

    private Outlet $mainBranch;

    private ExpenseCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mainBranch = Outlet::factory()->mainBranch()->create(['name' => 'Main Branch']);
        $this->category = ExpenseCategory::factory()->create(['name' => 'Utilities']);
    }

    /**
     * The expense routes open to both roles that handle spending.
     *
     * @return array<string, array{0: string}>
     */
    public static function routeProvider(): array
    {
        return [
            'index' => ['employee.expenses.index'],
            'create' => ['employee.expenses.create'],
        ];
    }

    #[DataProvider('routeProvider')]
    public function test_administrators_and_cashiers_may_record_expenses(string $route): void
    {
        foreach (UserRole::cases() as $role) {
            $response = $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route($route));

            $role === UserRole::Baker
                ? $response->assertForbidden()
                : $response->assertOk();
        }
    }

    #[DataProvider('routeProvider')]
    public function test_guests_are_redirected_to_the_login_page(string $route): void
    {
        $this->get(route($route))->assertRedirect(route('login'));
    }

    public function test_only_an_administrator_may_change_what_is_already_filed(): void
    {
        $expense = Expense::factory()->for($this->mainBranch)->for($this->category, 'category')->create();

        // Recording is Cashier work; correcting one is oversight (BR-007).
        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('employee.expenses.edit', $expense))
            ->assertForbidden();

        $this->actingAs(User::factory()->cashier()->create())
            ->get(route('employee.expenses.categories'))
            ->assertForbidden();

        $this->actingAs(User::factory()->administrator()->create())
            ->get(route('employee.expenses.edit', $expense))
            ->assertOk();
    }

    public function test_a_cashier_records_an_expense(): void
    {
        $cashier = User::factory()->cashier()->create();

        Livewire::actingAs($cashier)
            ->test('pages::employee.expenses.manage')
            ->set('description', 'Flour delivery')
            ->set('amount', '2450.50')
            ->set('expense_category_id', $this->category->id)
            ->set('outlet_id', $this->mainBranch->id)
            ->set('spent_on', now()->subDay()->toDateString())
            ->call('save')
            ->assertHasNoErrors();

        $expense = Expense::firstWhere('description', 'Flour delivery');

        $this->assertNotNull($expense);
        $this->assertSame('2450.50', $expense->amount);
        $this->assertSame(245050, $expense->amountInCentavos());
        $this->assertSame($this->mainBranch->id, $expense->outlet_id);
        $this->assertSame($this->category->id, $expense->expense_category_id);
        $this->assertSame($cashier->id, $expense->recorded_by);
        $this->assertSame(now()->subDay()->toDateString(), $expense->spent_on->toDateString());
    }

    public function test_it_refuses_an_empty_amount_a_future_date_or_no_description(): void
    {
        Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.expenses.manage')
            ->set('description', '')
            ->set('amount', '0')
            ->set('expense_category_id', $this->category->id)
            ->set('outlet_id', $this->mainBranch->id)
            ->set('spent_on', now()->addDay()->toDateString())
            ->call('save')
            ->assertHasErrors(['description', 'amount', 'spent_on']);

        $this->assertSame(0, Expense::count());
    }

    public function test_a_retired_category_cannot_be_chosen_for_something_new(): void
    {
        $retired = ExpenseCategory::factory()->retired()->create(['name' => 'Old heading']);

        Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.expenses.manage')
            ->set('description', 'Something')
            ->set('amount', '100')
            ->set('expense_category_id', $retired->id)
            ->set('outlet_id', $this->mainBranch->id)
            ->set('spent_on', now()->toDateString())
            ->call('save')
            ->assertHasErrors('expense_category_id');

        $this->assertSame(0, Expense::count());
    }

    public function test_editing_keeps_who_filed_it(): void
    {
        $cashier = User::factory()->cashier()->create();
        $expense = Expense::factory()
            ->for($this->mainBranch)
            ->for($this->category, 'category')
            ->amount('100.00')
            ->create(['recorded_by' => $cashier->id, 'description' => 'Typo']);

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.expenses.manage', ['expense' => $expense])
            ->set('description', 'Corrected')
            ->set('amount', '150.00')
            ->call('save')
            ->assertHasNoErrors();

        $expense->refresh();

        $this->assertSame('Corrected', $expense->description);
        $this->assertSame('150.00', $expense->amount);

        // The correction does not rewrite the audit trail.
        $this->assertSame($cashier->id, $expense->recorded_by);
    }

    public function test_the_listing_filters_by_date_location_and_category(): void
    {
        $kiosk = Outlet::factory()->create(['name' => 'Mall Kiosk']);
        $other = ExpenseCategory::factory()->create(['name' => 'Packaging']);

        Expense::factory()->for($this->mainBranch)->for($this->category, 'category')
            ->amount('1000.00')->spentOn(now()->toDateString())->create(['description' => 'Electricity']);

        Expense::factory()->for($kiosk)->for($other, 'category')
            ->amount('250.00')->spentOn(now()->toDateString())->create(['description' => 'Boxes']);

        Expense::factory()->for($this->mainBranch)->for($this->category, 'category')
            ->amount('9999.00')->spentOn(now()->subMonths(2)->toDateString())->create(['description' => 'Old bill']);

        $component = Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.expenses.index');

        // The month so far, everywhere: the two recent ones, not the old bill.
        $component->assertSet('total', '1,250.00')
            ->assertSee('Electricity')
            ->assertSee('Boxes')
            ->assertDontSee('Old bill');

        $component->set('outletId', $kiosk->id)
            ->assertSet('total', '250.00')
            ->assertSee('Boxes')
            ->assertDontSee('Electricity');

        $component->set('outletId', 0)
            ->set('categoryId', $this->category->id)
            ->assertSet('total', '1,000.00');

        // Widening the dates brings the old bill back.
        $component->set('categoryId', 0)
            ->set('from', now()->subMonths(3)->toDateString())
            ->assertSee('Old bill');
    }

    public function test_the_summary_breaks_the_total_down_by_category(): void
    {
        $packaging = ExpenseCategory::factory()->create(['name' => 'Packaging']);

        Expense::factory()->for($this->mainBranch)->for($this->category, 'category')->amount('300.00')->create();
        Expense::factory()->for($this->mainBranch)->for($this->category, 'category')->amount('200.00')->create();
        Expense::factory()->for($this->mainBranch)->for($packaging, 'category')->amount('125.50')->create();

        $summary = Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.expenses.index')
            ->instance()
            ->byCategory()
            ->keyBy('name');

        $this->assertSame('500.00', $summary['Utilities']['total']);
        $this->assertSame('125.50', $summary['Packaging']['total']);
    }

    public function test_an_administrator_removes_an_expense_filed_in_error(): void
    {
        $expense = Expense::factory()->for($this->mainBranch)->for($this->category, 'category')->create();

        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.expenses.index')
            ->call('delete', $expense->id);

        $this->assertDatabaseMissing('expenses', ['id' => $expense->id]);
    }

    public function test_a_cashier_cannot_remove_one(): void
    {
        $expense = Expense::factory()->for($this->mainBranch)->for($this->category, 'category')->create();

        Livewire::actingAs(User::factory()->cashier()->create())
            ->test('pages::employee.expenses.index')
            ->call('delete', $expense->id)
            ->assertForbidden();

        $this->assertDatabaseHas('expenses', ['id' => $expense->id]);
    }

    public function test_an_administrator_adds_and_retires_a_category(): void
    {
        $component = Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.expenses.categories')
            ->set('name', 'Licences')
            ->call('add')
            ->assertHasNoErrors();

        $added = ExpenseCategory::firstWhere('name', 'Licences');

        $this->assertNotNull($added);
        $this->assertTrue($added->is_active);

        $component->call('toggleActive', $added->id);

        // Retired, not deleted — anything filed under it keeps its meaning.
        $this->assertFalse($added->fresh()->is_active);
        $this->assertDatabaseHas('expense_categories', ['id' => $added->id]);
    }

    public function test_a_duplicate_category_is_refused(): void
    {
        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.expenses.categories')
            ->set('name', 'Utilities')
            ->call('add')
            ->assertHasErrors('name');

        $this->assertSame(1, ExpenseCategory::where('name', 'Utilities')->count());
    }
}
