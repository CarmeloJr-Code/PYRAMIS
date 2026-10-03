<?php

namespace Database\Seeders;

use App\Actions\AssignShift;
use App\Actions\DeliverRestock;
use App\Actions\RecordInventoryMovement;
use App\Actions\RecordProductionRun;
use App\Actions\RecordProductStockMovement;
use App\Actions\RecordSaleForOrder;
use App\Actions\StartConversation;
use App\Actions\VoidSale;
use App\Enums\InventoryMovementType;
use App\Enums\OrderStatus;
use App\Enums\ProductStockMovementType;
use App\Enums\RestockStatus;
use App\Enums\SaleStatus;
use App\Enums\UserRole;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Ingredient;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\ProductStockMovement;
use App\Models\ProductVariant;
use App\Models\Recipe;
use App\Models\Restock;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Weeks of a working bakery, for a development database.
 *
 * Not reference data and never part of a deploy: run it by hand, on a database
 * that already holds the reference seed, with
 * `php artisan db:seed --class=DemoSeeder`.
 *
 * The history is made by doing the work rather than by writing rows — each day
 * is lived through with the clock set to it, and every bake, delivery, sale and
 * shift goes through the same action the workspace uses. So the ledgers obey
 * the rules the application enforces: nothing is sold that was not baked and
 * carried to the outlet, and nothing is baked from flour that was never
 * received. Where a rule says no, the step is skipped, as it would be on a real
 * morning.
 *
 * Seeded randomness, so every run tells the same story. Nothing is dated past
 * the moment it runs, so today is only as far along as the real day is.
 *
 * Deliberately without WithoutModelEvents: orders, sales and restocks are given
 * their references by a creating hook.
 */
class DemoSeeder extends Seeder
{
    /**
     * How busy each weekday is against the average, Sunday first.
     *
     * @var array<int, float>
     */
    private const WEEKDAY_FACTORS = [1.4, 0.7, 0.85, 0.9, 0.95, 1.15, 1.5];

    /**
     * Units a day across every outlet, by size name.
     *
     * @var array<string, float>
     */
    private const SIZE_DEMAND = [
        'Large (10x14)' => 0.5,
        'Medium (7x10)' => 1.0,
        'Round (7x3)' => 2.0,
        'Heart (6" dia)' => 1.2,
        'Tincan Round' => 3.0,
        'Tincan Heart' => 2.0,
        'Messy Cup (12 oz)' => 6.0,
        'Messy Cup (8 oz)' => 8.0,
        'Round' => 1.0,
        'Slice' => 6.0,
        'Tincan/Tub' => 2.0,
        'Layer' => 1.0,
        '12 oz' => 2.5,
        '8 oz' => 4.0,
        'Large (1 large cake)' => 0.3,
        'Medium (up to 2 medium cakes)' => 0.5,
        'Small (4 tincans max cap)' => 1.0,
        'Tiny (2 tincans max cap)' => 1.0,
    ];

    /**
     * How a product sells against the ube cake.
     *
     * @var array<string, float>
     */
    private const PRODUCT_POPULARITY = [
        'Chocolate Cake' => 0.6,
    ];

    /**
     * How much of a base batch one unit of each size takes.
     *
     * @var array<string, float>
     */
    private const SIZE_SCALE = [
        'Large (10x14)' => 4.0,
        'Medium (7x10)' => 2.5,
        'Round (7x3)' => 1.5,
        'Heart (6" dia)' => 1.5,
        'Tincan Round' => 0.8,
        'Tincan Heart' => 0.8,
        'Messy Cup (12 oz)' => 0.35,
        'Messy Cup (8 oz)' => 0.25,
        'Round' => 1.5,
        'Slice' => 0.15,
        'Tincan/Tub' => 0.8,
        'Layer' => 2.0,
        '12 oz' => 0.5,
        '8 oz' => 0.3,
    ];

    /**
     * Invented base batches, by product and ingredient name.
     *
     * Sample figures to make production draw on the stockroom, not the
     * bakery's recipes (docs/reference/recipes/ is reference only).
     *
     * @var array<string, array<string, int|float>>
     */
    private const RECIPES = [
        'Ube Cake' => [
            'Purple Yam Dry Premix' => 0.5,
            'Purple Yam Wet Premix' => 0.5,
            'Extra Large Eggs' => 3,
            'White Sugar' => 0.15,
            'Cooking Oil' => 0.08,
            'Evaporated Filled Milk' => 0.5,
            'Ube Food Color' => 2,
        ],
        'Ube Custard Cake' => [
            'Ube Base Premix' => 0.5,
            'Extra Large Eggs' => 4,
            'Condensed Milk' => 1,
            'Evaporated Filled Milk' => 0.5,
            'White Sugar' => 0.1,
            'Vanilla Extract' => 5,
        ],
        'Chobe Cake' => [
            'Purple Yam Dry Premix' => 0.4,
            'Purple Yam Wet Premix' => 0.4,
            'Extra Large Eggs' => 3,
            'White Sugar' => 0.15,
            'Cooking Oil' => 0.08,
            'All-Purpose Cream' => 0.5,
        ],
        'Chocolate Cake' => [
            'All-Purpose Flour' => 0.3,
            'White Sugar' => 0.25,
            'Extra Large Eggs' => 4,
            'Cooking Oil' => 0.1,
            'Baking Powder' => 10,
            'Evaporated Filled Milk' => 0.5,
            'Vanilla Extract' => 5,
        ],
        'Ube Calamansi Bar' => [
            'Ube Base Premix' => 0.5,
            'Calamansi Syrup' => 0.1,
            'Confectioner Sugar' => 0.2,
            'Extra Large Eggs' => 3,
            'Vegetable Shortening' => 0.1,
        ],
        'Ube Creamcheese Bar' => [
            'Ube Base Premix' => 0.5,
            'Cream Cheese' => 0.25,
            'Confectioner Sugar' => 0.15,
            'Extra Large Eggs' => 3,
        ],
        'Ube Brazo de Mercedes' => [
            'Extra Large Eggs' => 6,
            'Cream of Tartar' => 3,
            'White Sugar' => 0.15,
            'Condensed Milk' => 1,
            'Ube Powder' => 20,
        ],
    ];

    /**
     * Sizes customers pre-order — whole cakes, not cups and slices.
     *
     * @var list<string>
     */
    private const ORDERABLE_SIZES = [
        'Large (10x14)',
        'Medium (7x10)',
        'Round (7x3)',
        'Heart (6" dia)',
        'Tincan Round',
        'Tincan Heart',
        'Round',
        'Layer',
    ];

    /**
     * @var list<string>
     */
    private const CUSTOMERS = [
        'Maria Santos', 'Jose Reyes', 'Ana Cruz', 'Juan Bautista', 'Liza Villanueva',
        'Mark Gonzales', 'Grace Ramos', 'Paolo Mendoza', 'Kristine Aquino', 'Ramon Garcia',
        'Jessa Fernandez', 'Carlo Navarro', 'Rowena Castillo', 'Dennis Flores', 'Mylene Torres',
        'Arnel Domingo', 'Joy Pascual', 'Rhea Lopez', 'Noel Salazar', 'Cherry Dizon',
    ];

    /**
     * @var list<string>
     */
    private const ORDER_NOTES = [
        'Please write "Happy Birthday, Lola!" on top.',
        'For a christening — no message, thank you.',
        'Please write "Congratulations, Class of 2026".',
        'Will pick up a little early if possible.',
        'Please write "Happy Anniversary, Ma and Pa".',
    ];

    /**
     * Conversations staff have, starter's role first.
     *
     * @var list<array{0: UserRole, 1: UserRole, 2: string, 3: list<string>}>
     */
    private const CONVERSATIONS = [
        [UserRole::Administrator, UserRole::Baker, 'Weekend bake plan', [
            'Saturday pre-orders are heavy again. Can we add two more batches of tincans?',
            'Noted. We will start an hour earlier on Saturday.',
            'Thanks — I will move the opening shift on the schedule.',
        ]],
        [UserRole::Baker, UserRole::Administrator, 'Eggs running low', [
            'We are down to a few trays of eggs after this morning.',
            'Supplier delivers Thursday. Is that enough to cover Wednesday?',
            'Should be, if we hold back on the brazo for a day.',
        ]],
        [UserRole::Cashier, UserRole::Administrator, 'Custom message on a cake', [
            'A customer is asking if we can pipe a name on a tincan cake.',
            'Yes, short names only. Put it in the order notes so the bakers see it.',
        ]],
        [UserRole::Administrator, UserRole::Cashier, 'Pickup reminders', [
            'Please check the Ready list before closing so no pre-order is left on the shelf.',
            'Will do. Two are still waiting from this afternoon.',
            'Call them before 6 if they have not arrived.',
        ]],
        [UserRole::Cashier, UserRole::Baker, 'Messy cups sold out early', [
            'The 8 oz messy cups were gone by 3pm today.',
            'We can bake an extra tray tomorrow morning.',
        ]],
        [UserRole::Administrator, UserRole::Baker, 'Stock count on Sunday', [
            'Stockroom count after closing on Sunday, please.',
            'Okay. Shall we include the packaging shelf?',
            'Yes, all of it.',
        ]],
    ];

    /**
     * When the seed was started; nothing is recorded after it.
     */
    private CarbonImmutable $cutoff;

    private User $administrator;

    /** @var Collection<int, User> */
    private Collection $bakers;

    /** @var Collection<int, User> */
    private Collection $cashiers;

    private Outlet $mainBranch;

    /** @var Collection<int, Outlet> */
    private Collection $outlets;

    /** @var Collection<int, ProductVariant> keyed by id */
    private Collection $variants;

    /** @var array<int, float> base daily demand, keyed by variant id */
    private array $demand = [];

    /** @var array<int, float> expected daily use in thousandths, keyed by ingredient id */
    private array $dailyUsage = [];

    /** @var array<string, ExpenseCategory> */
    private array $categories = [];

    /** @var array<int, array<int, int>> units on each shelf, by outlet id then variant id */
    private array $shelves = [];

    public function __construct(private int $days = 56) {}

    /**
     * Live through the days, oldest first.
     *
     * @throws RuntimeException in production, or against the hosted database
     */
    public function run(): void
    {
        $this->refuseOutsideDevelopment();

        if (Sale::query()->exists()) {
            $this->command->warn('Sales already exist, so the demo history has been seeded before. Run migrate:fresh --seed first.');

            return;
        }

        $this->call(ProductionSeeder::class);

        mt_srand(2026);

        $this->cutoff = CarbonImmutable::now();
        $start = $this->cutoff->startOfDay()->subDays($this->days - 1);

        try {
            Carbon::setTestNow($start->setTime(3, 0));

            $this->prepareBakery();

            for ($index = 0; $index < $this->days; $index++) {
                $this->liveThrough($start->addDays($index), $index);
            }
        } finally {
            Carbon::setTestNow();
        }

        $this->leaveTomorrowsRestockWaiting();
    }

    /**
     * Never in production, and never against Supabase, whatever APP_ENV says.
     *
     * @throws RuntimeException
     */
    private function refuseOutsideDevelopment(): void
    {
        $host = (string) config('database.connections.'.config('database.default').'.host');

        if (app()->isProduction() || str_contains($host, 'supabase')) {
            throw new RuntimeException('The demo history is for a development database only.');
        }
    }

    /**
     * What has to exist before the first morning: staff, outlets, recipes.
     */
    private function prepareBakery(): void
    {
        $this->administrator = User::query()->active()->where('role', UserRole::Administrator)->oldest('id')->first()
            ?? User::factory()->administrator()->create(['name' => 'Purple Yam Administrator', 'email' => 'administrator@purpleyam.test']);

        $this->bakers = $this->staff(UserRole::Baker, ['Rogelio Bacus', 'Analyn Sumalinog', 'Jerome Lagumbay']);
        $this->cashiers = $this->staff(UserRole::Cashier, ['Kimberly Dalogdog', 'Ritchie Manos', 'Hazel Pabelonia', 'Jonas Tagalog', 'Mae Ondona', 'Lovely Galarrita']);

        $this->mainBranch = Outlet::mainBranch();

        if (! Outlet::query()->where('is_main_branch', false)->exists()) {
            Outlet::create(['name' => 'Sayre Highway Outlet', 'address' => 'Sayre Highway, Malaybalay, Bukidnon', 'phone' => null, 'is_active' => true]);
            Outlet::create(['name' => 'Capitol Outlet', 'address' => 'Fortich Street, Malaybalay, Bukidnon', 'phone' => null, 'is_active' => true]);
        }

        $this->outlets = Outlet::query()->active()->orderByDesc('is_main_branch')->orderBy('id')->get();

        $this->variants = ProductVariant::query()
            ->available()
            ->whereHas('product', fn ($query) => $query->where('is_active', true))
            ->with('product')
            ->get()
            ->keyBy('id');

        foreach ($this->variants as $variant) {
            $this->demand[$variant->id] = (self::SIZE_DEMAND[$variant->name] ?? 1.0)
                * (self::PRODUCT_POPULARITY[$variant->product->name] ?? 1.0);
        }

        $this->writeRecipes();
        $this->setReorderLevels();

        $this->categories = ExpenseCategory::query()->get()->keyBy('name')->all();
    }

    /**
     * The seeded employees of a role, topped up with the named ones.
     *
     * @param  list<string>  $names
     * @return Collection<int, User>
     */
    private function staff(UserRole $role, array $names): Collection
    {
        foreach ($names as $name) {
            $email = str_replace(' ', '.', strtolower($name)).'@purpleyam.test';

            if (! User::query()->where('email', $email)->exists()) {
                User::factory()->state(['role' => $role])->create(['name' => $name, 'email' => $email]);
            }
        }

        return User::query()->active()->where('role', $role)->orderBy('id')->get()->values();
    }

    /**
     * A recipe for every bakeable size that has none.
     */
    private function writeRecipes(): void
    {
        $ingredients = Ingredient::query()->active()->get()->keyBy('name');

        foreach ($this->variants as $variant) {
            $base = self::RECIPES[$variant->product->name] ?? null;

            if ($base === null || Recipe::query()->where('product_variant_id', $variant->id)->exists()) {
                continue;
            }

            $scale = self::SIZE_SCALE[$variant->name] ?? 1.0;

            $recipe = Recipe::create([
                'product_variant_id' => $variant->id,
                'yield_quantity' => 1,
                'notes' => 'Sample recipe from the demo seed.',
            ]);

            foreach ($base as $name => $quantity) {
                if (! $ingredients->has($name)) {
                    continue;
                }

                $recipe->items()->create([
                    'ingredient_id' => $ingredients[$name]->id,
                    'quantity' => number_format($quantity * $scale, 3, '.', ''),
                ]);
            }
        }
    }

    /**
     * Reorder levels at three days' use, where the Administrator has set none.
     */
    private function setReorderLevels(): void
    {
        $recipes = Recipe::query()->with('items')->whereIn('product_variant_id', $this->variants->keys())->get();

        foreach ($recipes as $recipe) {
            foreach ($recipe->requirementsInThousandths(1) as $ingredientId => $perUnit) {
                // Planned with headroom over the average day, so twice-weekly
                // receipts keep up with a weekend and the upward trend.
                $this->dailyUsage[$ingredientId] = ($this->dailyUsage[$ingredientId] ?? 0)
                    + $perUnit * $this->demand[$recipe->product_variant_id] * 1.3;
            }
        }

        foreach (Ingredient::query()->active()->get() as $ingredient) {
            $usage = $this->dailyUsage[$ingredient->id] ?? 0;

            if ($usage > 0 && $ingredient->reorderLevelInThousandths() === 0) {
                $ingredient->update(['reorder_level' => number_format(ceil($usage * 3 / 1000), 3, '.', '')]);
            }
        }
    }

    /**
     * One day at the bakery, from the first receipt to the last sale.
     */
    private function liveThrough(CarbonImmutable $day, int $index): void
    {
        if ($index === 0) {
            $this->schedule($day, (7 - $day->dayOfWeek) % 7 + 1);
        }

        if ($index === 0 || $day->isMonday() || $day->isThursday()) {
            $this->receiveIngredients($day, opening: $index === 0);
        }

        $factor = self::WEEKDAY_FACTORS[$day->dayOfWeek] * (0.85 + 0.3 * $index / max(1, $this->days - 1));

        $this->bake($day, $factor);
        $this->restockOutlets($day, $factor);
        $this->moveOrdersAlong($day);
        $this->trade($day, $factor);
        $this->takePreOrders($day);
        $this->recordWastage($day);
        $this->recordExpenses($day);
        $this->talk($day, $index);

        if ($day->isSunday()) {
            $this->schedule($day->addDay(), 7);
        }
    }

    /**
     * Set the clock, unless the moment has not happened yet.
     */
    private function at(CarbonImmutable $moment): bool
    {
        if ($moment->greaterThan($this->cutoff)) {
            return false;
        }

        Carbon::setTestNow($moment);

        return true;
    }

    /**
     * Stockroom receipts: everything below a week's use is brought up to ten
     * days'. The first is the opening stock.
     */
    private function receiveIngredients(CarbonImmutable $day, bool $opening): void
    {
        if (! $this->at($day->setTime(4, 0))) {
            return;
        }

        $movements = app(RecordInventoryMovement::class);
        $received = false;

        foreach (Ingredient::query()->active()->withStock()->get() as $ingredient) {
            $usage = $this->dailyUsage[$ingredient->id] ?? 0;
            $stock = $ingredient->stockInThousandths();

            if ($usage <= 0 || $stock >= $usage * 7) {
                continue;
            }

            $quantity = (int) ceil(($usage * 10 - $stock) / 1000) * 1000;

            $movements->handle($ingredient, $this->administrator, InventoryMovementType::Received, $quantity, $opening ? 'Opening stock' : null);
            $received = true;
        }

        if ($received) {
            $this->expense($day, 'Ingredients and supplies', $this->mainBranch, $opening ? $this->between(18000, 24000) : $this->between(4500, 9000), 'Stockroom receipt — premixes, eggs and dairy', hour: 4);
        }
    }

    /**
     * The morning bake on the main branch: tomorrow's shelves, less what is
     * already on them, plus every pre-order due today.
     */
    private function bake(CarbonImmutable $day, float $factor): void
    {
        if (! $this->at($day->setTime(5, 0))) {
            return;
        }

        $this->refreshShelves();

        $due = $this->orderedUnits($day);
        $baking = app(RecordProductionRun::class);
        $runs = 0;

        foreach ($this->variants as $variant) {
            $onShelves = array_sum(array_map(fn (array $shelf): int => $shelf[$variant->id] ?? 0, $this->shelves));

            $needed = (int) round($this->demand[$variant->id] * $factor * 1.15) + ($due[$variant->id] ?? 0) - $onShelves;

            if ($needed < 1) {
                continue;
            }

            if (! isset(self::RECIPES[$variant->product->name])) {
                $this->receiveMerchandise($variant, $needed);

                continue;
            }

            if (! $this->at($day->setTime(5, 0)->addMinutes(7 * $runs++))) {
                return;
            }

            try {
                $baking->handle($variant, $this->pick($this->bakers), $needed);
            } catch (RuntimeException) {
                // The stockroom could not cover it — the size waits a day.
            }
        }
    }

    /**
     * Tote bags come from a supplier, not the oven, so they arrive by
     * adjustment.
     */
    private function receiveMerchandise(ProductVariant $variant, int $needed): void
    {
        app(RecordProductStockMovement::class)->handle(
            $variant,
            $this->mainBranch,
            $this->administrator,
            ProductStockMovementType::Adjustment,
            max($needed, 10) * 3,
            'Received from supplier',
        );
    }

    /**
     * A delivery to each pickup outlet of what the day will sell there.
     */
    private function restockOutlets(CarbonImmutable $day, float $factor): void
    {
        if (! $this->at($day->setTime(6, 30))) {
            return;
        }

        $this->refreshShelves();

        foreach ($this->outlets->where('is_main_branch', false) as $offset => $outlet) {
            $due = $this->orderedUnits($day, $outlet);
            $share = $this->share($outlet);
            $lines = [];

            foreach ($this->variants as $variant) {
                $wanted = (int) round($this->demand[$variant->id] * $factor * $share * 1.1) + ($due[$variant->id] ?? 0)
                    - ($this->shelves[$outlet->id][$variant->id] ?? 0);

                if ($wanted > 0) {
                    $lines[$variant->id] = $wanted;
                }
            }

            if ($lines === []) {
                continue;
            }

            if (! $this->at($day->setTime(6, 30)->addMinutes(10 * $offset))) {
                return;
            }

            $restock = DB::transaction(function () use ($outlet, $day, $lines): Restock {
                $restock = Restock::create([
                    'outlet_id' => $outlet->id,
                    'status' => RestockStatus::Requested,
                    'scheduled_for' => $day->toDateString(),
                    'requested_by' => $this->administrator->id,
                    'notes' => null,
                ]);

                foreach ($lines as $variantId => $quantity) {
                    $restock->items()->create(['product_variant_id' => $variantId, 'quantity_requested' => $quantity]);
                }

                return $restock;
            });

            $baker = $this->pick($this->bakers);

            foreach ($restock->items as $item) {
                $available = $this->shelves[$this->mainBranch->id][$item->product_variant_id] ?? 0;
                $prepared = max(0, min($item->quantity_requested, $available));

                $item->update(['quantity_prepared' => $prepared]);
                $this->shelves[$this->mainBranch->id][$item->product_variant_id] = $available - $prepared;
            }

            if ($restock->preparedUnits() === 0) {
                $restock->transitionTo(RestockStatus::Cancelled);

                continue;
            }

            $restock->transitionTo(RestockStatus::Preparing);
            $restock->prepared_by = $baker->id;
            $restock->save();

            // Still on the main branch's floor, if the van has not left yet.
            if ($this->at(now()->toImmutable()->addMinutes(5))) {
                app(DeliverRestock::class)->handle($restock, $baker);
            }
        }
    }

    /**
     * Yesterday's pre-orders are confirmed or cancelled; today's are prepared
     * and set out for pickup.
     */
    private function moveOrdersAlong(CarbonImmutable $day): void
    {
        if (! $this->at($day->setTime(7, 45))) {
            return;
        }

        foreach (Order::query()->where('status', OrderStatus::Pending)->where('created_at', '<', $day)->get() as $order) {
            $order->transitionTo($this->chance(0.08) ? OrderStatus::Cancelled : OrderStatus::Confirmed);
        }

        $dueToday = fn (OrderStatus $status) => Order::query()
            ->where('status', $status)
            ->whereBetween('pickup_at', [$day, $day->endOfDay()])
            ->get();

        if ($this->at($day->setTime(8, 0))) {
            $dueToday(OrderStatus::Confirmed)->each(fn (Order $order) => $order->transitionTo(OrderStatus::Preparing));
        }

        if ($this->at($day->setTime(9, 30))) {
            $dueToday(OrderStatus::Preparing)->each(fn (Order $order) => $order->transitionTo(OrderStatus::Ready));
        }
    }

    /**
     * Opening hours: walk-in customers at every counter, and pre-orders
     * collected when they are due.
     */
    private function trade(CarbonImmutable $day, float $factor): void
    {
        $this->refreshShelves();

        /** @var list<array{0: CarbonImmutable, 1: Outlet, 2: array<int, int>|Order}> $events */
        $events = [];
        $reserved = [];

        foreach ($this->outlets as $outlet) {
            $share = $this->share($outlet);
            $units = [];

            foreach ($this->variants as $variant) {
                $count = $this->draw($this->demand[$variant->id] * $factor * $share);
                array_push($units, ...array_fill(0, $count, $variant->id));
            }

            shuffle($units);

            while ($units !== []) {
                $basket = array_count_values(array_splice($units, 0, $this->between(1, 3)));
                $events[] = [$day->setTime(8, 0)->addMinutes($this->between(0, 599)), $outlet, $basket];
            }
        }

        $collecting = Order::query()
            ->where('status', OrderStatus::Ready)
            ->whereBetween('pickup_at', [$day, $day->endOfDay()])
            ->with('items')
            ->get();

        foreach ($collecting as $order) {
            $events[] = [CarbonImmutable::parse($order->pickup_at)->addMinutes($this->between(-20, 40)), $this->outlets->firstWhere('id', $order->outlet_id), $order];

            foreach ($order->items as $item) {
                $reserved[$order->outlet_id][$item->product_variant_id] = ($reserved[$order->outlet_id][$item->product_variant_id] ?? 0) + $item->quantity;
            }
        }

        usort($events, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        foreach ($events as [$moment, $outlet, $what]) {
            if ($outlet === null || ! $this->at($moment)) {
                continue;
            }

            $cashier = $this->pick($this->cashiers);

            if ($what instanceof Order) {
                app(RecordSaleForOrder::class)->handle($what, $cashier);

                foreach ($what->items as $item) {
                    $this->shelves[$outlet->id][$item->product_variant_id] = ($this->shelves[$outlet->id][$item->product_variant_id] ?? 0) - $item->quantity;
                    $reserved[$outlet->id][$item->product_variant_id] -= $item->quantity;
                }

                continue;
            }

            $this->sellOverTheCounter($outlet, $cashier, $what, $reserved[$outlet->id] ?? []);
        }
    }

    /**
     * A walk-in sale, rung up as the counter screen does — but only of what is
     * on the shelf and not set aside for a pre-order.
     *
     * @param  array<int, int>  $basket
     * @param  array<int, int>  $reserved
     */
    private function sellOverTheCounter(Outlet $outlet, User $cashier, array $basket, array $reserved): void
    {
        $lines = [];

        foreach ($basket as $variantId => $quantity) {
            $free = ($this->shelves[$outlet->id][$variantId] ?? 0) - ($reserved[$variantId] ?? 0);
            $quantity = min($quantity, $free);

            if ($quantity > 0) {
                $lines[$variantId] = $quantity;
            }
        }

        if ($lines === []) {
            return;
        }

        $sale = DB::transaction(function () use ($outlet, $cashier, $lines): Sale {
            $sale = Sale::create([
                'outlet_id' => $outlet->id,
                'order_id' => null,
                'recorded_by' => $cashier->id,
                'status' => SaleStatus::Completed,
                'sold_at' => now(),
            ]);

            $movements = app(RecordProductStockMovement::class);

            foreach ($lines as $variantId => $quantity) {
                $variant = $this->variants[$variantId];

                $sale->items()->create([
                    'product_variant_id' => $variant->id,
                    'quantity' => $quantity,
                    'unit_price' => $variant->price,
                ]);

                $movements->handle($variant, $outlet, $cashier, ProductStockMovementType::Sale, -$quantity, null, null, null, $sale);
            }

            return $sale;
        });

        foreach ($lines as $variantId => $quantity) {
            $this->shelves[$outlet->id][$variantId] -= $quantity;
        }

        // The odd sale rung up by mistake, and voided at once.
        if ($this->chance(1 / 80) && $this->at(now()->toImmutable()->addMinutes(3))) {
            app(VoidSale::class)->handle($sale, $cashier);

            foreach ($lines as $variantId => $quantity) {
                $this->shelves[$outlet->id][$variantId] += $quantity;
            }
        }
    }

    /**
     * Pre-orders placed on the storefront today, for pickup in the next few
     * days.
     */
    private function takePreOrders(CarbonImmutable $day): void
    {
        $orderable = $this->variants
            ->filter(fn (ProductVariant $variant): bool => in_array($variant->name, self::ORDERABLE_SIZES, true))
            ->values();

        if ($orderable->isEmpty()) {
            return;
        }

        $placed = [];

        for ($count = $this->between(1, 4); $count > 0; $count--) {
            $placed[] = $day->setTime(7, 0)->addMinutes($this->between(0, 780));
        }

        sort($placed);

        foreach ($placed as $moment) {
            if (! $this->at($moment)) {
                return;
            }

            $customer = self::CUSTOMERS[$this->between(0, count(self::CUSTOMERS) - 1)];
            $lines = [];

            for ($line = $this->between(1, 2); $line > 0; $line--) {
                $lines[$this->pick($orderable)->id] = $this->between(1, 2);
            }

            DB::transaction(function () use ($day, $customer, $lines): void {
                $order = Order::create([
                    'outlet_id' => $this->pick($this->outlets)->id,
                    'customer_name' => $customer,
                    'customer_phone' => '09'.$this->between(10, 99).$this->between(1000000, 9999999),
                    'customer_email' => $this->chance(0.4) ? str_replace(' ', '.', strtolower($customer)).'@example.com' : null,
                    'pickup_at' => $day->addDays($this->between(1, 3))->setTime($this->between(10, 16), $this->pick(collect([0, 30]))),
                    'notes' => $this->chance(0.3) ? self::ORDER_NOTES[$this->between(0, count(self::ORDER_NOTES) - 1)] : null,
                    'status' => OrderStatus::Pending,
                ]);

                foreach ($lines as $variantId => $quantity) {
                    $order->items()->create([
                        'product_variant_id' => $variantId,
                        'quantity' => $quantity,
                        'unit_price' => $this->variants[$variantId]->price,
                    ]);
                }
            });
        }
    }

    /**
     * Now and then something is dropped, cracked or spoiled.
     */
    private function recordWastage(CarbonImmutable $day): void
    {
        if (! $this->chance(0.15) || ! $this->at($day->setTime(15, 0))) {
            return;
        }

        $ingredient = Ingredient::query()->active()->where('name', 'Extra Large Eggs')->first();

        if ($ingredient === null) {
            return;
        }

        try {
            app(RecordInventoryMovement::class)->handle(
                $ingredient,
                $this->pick($this->bakers),
                InventoryMovementType::Adjustment,
                -$this->between(2, 8) * 1000,
                $this->chance(0.5) ? 'Cracked in the tray' : 'Dropped during prep',
            );
        } catch (RuntimeException) {
            // Nothing left to break.
        }
    }

    /**
     * The running costs: payroll and packaging on Saturdays, fuel on
     * Wednesdays, rent and utilities early in the month, and the odd repair.
     */
    private function recordExpenses(CarbonImmutable $day): void
    {
        if ($day->isSaturday()) {
            foreach ($this->outlets as $outlet) {
                $this->expense($day, 'Wages and benefits', $outlet, $outlet->is_main_branch ? $this->between(14000, 16000) : $this->between(6500, 7500), 'Weekly payroll');
            }

            $this->expense($day, 'Packaging', $this->mainBranch, $this->between(900, 1800), 'Cake boxes, cups and lids');
        }

        if ($day->isWednesday()) {
            $this->expense($day, 'Transport and delivery', $this->mainBranch, $this->between(500, 950), 'Fuel for outlet deliveries');
        }

        if ($day->day === 1) {
            foreach ($this->outlets as $outlet) {
                $this->expense($day, 'Rent', $outlet, $outlet->is_main_branch ? 15000 : 8000, 'Monthly rent');
            }
        }

        if ($day->day === 5) {
            foreach ($this->outlets as $outlet) {
                $this->expense($day, 'Utilities', $outlet, $outlet->is_main_branch ? $this->between(7000, 9500) : $this->between(2500, 3500), 'Electricity and water');
            }
        }

        if ($this->chance(0.05)) {
            $this->expense($day, 'Equipment and maintenance', $this->mainBranch, $this->between(600, 3500), $this->chance(0.5) ? 'Oven thermostat repair' : 'Mixer belt replacement');
        }
    }

    /**
     * One expense, written down at the given hour of the day it was spent.
     */
    private function expense(CarbonImmutable $day, string $category, Outlet $outlet, int $pesos, string $description, int $hour = 18): void
    {
        if (! isset($this->categories[$category]) || ! $this->at($day->setTime($hour, 30))) {
            return;
        }

        Expense::create([
            'expense_category_id' => $this->categories[$category]->id,
            'outlet_id' => $outlet->id,
            'amount' => number_format($pesos, 2, '.', ''),
            'spent_on' => $day->toDateString(),
            'description' => $description,
            'recorded_by' => $this->administrator->id,
        ]);
    }

    /**
     * A conversation every few days, replied to over the afternoon.
     */
    private function talk(CarbonImmutable $day, int $index): void
    {
        if ($index % 3 !== 1 || ! $this->at($day->setTime(13, 15))) {
            return;
        }

        [$from, $to, $subject, $lines] = self::CONVERSATIONS[intdiv($index, 3) % count(self::CONVERSATIONS)];

        $starter = $this->member($from);
        $other = $this->member($to, except: $starter);

        $conversation = app(StartConversation::class)->handle($starter, $subject, [$other->id], array_shift($lines));

        foreach ($lines as $turn => $line) {
            if (! $this->at($day->setTime(13, 15)->addMinutes(25 * ($turn + 1)))) {
                return;
            }

            $conversation->postMessage($turn % 2 === 0 ? $other : $starter, $line);
        }
    }

    /**
     * Someone in a role.
     */
    private function member(UserRole $role, ?User $except = null): User
    {
        $people = match ($role) {
            UserRole::Administrator => collect([$this->administrator]),
            UserRole::Baker => $this->bakers,
            UserRole::Cashier => $this->cashiers,
        };

        return $this->pick($people->reject(fn (User $user): bool => $user->is($except))->values());
    }

    /**
     * The roster for a run of days, drawn up the evening before it starts.
     *
     * Everybody gets one day off a week, on a weekday that rotates with their
     * place on the list.
     */
    private function schedule(CarbonImmutable $from, int $days): void
    {
        if (! $this->at($from->subDay()->setTime(17, 0))) {
            Carbon::setTestNow($this->cutoff);
        }

        $assign = app(AssignShift::class);

        for ($offset = 0; $offset < $days; $offset++) {
            $day = $from->addDays($offset);

            $slots = [[$this->mainBranch, 'Opening bake', 4, 12, $this->bakers], [$this->mainBranch, 'Opening bake', 4, 12, $this->bakers], [$this->mainBranch, 'Afternoon bake', 12, 20, $this->bakers]];

            foreach ($this->outlets as $outlet) {
                $slots[] = [$outlet, 'Counter AM', 7, 13, $this->cashiers];
                $slots[] = [$outlet, 'Counter PM', 13, 19, $this->cashiers];
            }

            $shifts = [];
            $working = [];

            foreach ($slots as [$outlet, $name, $starts, $ends, $people]) {
                $key = $outlet->id.$name;

                $shifts[$key] ??= Shift::create([
                    'outlet_id' => $outlet->id,
                    'starts_at' => $day->setTime($starts, 0),
                    'ends_at' => $day->setTime($ends, 0),
                    'name' => $name,
                    'created_by' => $this->administrator->id,
                ]);

                $available = $people
                    ->filter(fn (User $user, int $position): bool => ($position + $day->dayOfYear) % 7 !== 0 && ! in_array($user->id, $working, true))
                    ->values();

                if ($available->isEmpty()) {
                    continue;
                }

                $employee = $available[$day->dayOfYear % $available->count()];

                try {
                    $assign->handle($shifts[$key], $employee, $this->administrator);
                    $working[] = $employee->id;
                } catch (RuntimeException) {
                    // Already on something then — the shift stays short.
                }
            }
        }
    }

    /**
     * One restock left in the queue for tomorrow, so the restock screen has
     * something waiting on it.
     */
    private function leaveTomorrowsRestockWaiting(): void
    {
        $outlet = Outlet::query()->active()->where('is_main_branch', false)->orderBy('id')->first();
        $variants = $this->variants->filter(fn (ProductVariant $variant): bool => isset(self::RECIPES[$variant->product->name]))->take(3);

        if ($outlet === null || $variants->isEmpty()) {
            return;
        }

        $restock = Restock::create([
            'outlet_id' => $outlet->id,
            'status' => RestockStatus::Requested,
            'scheduled_for' => $this->cutoff->addDay()->toDateString(),
            'requested_by' => $this->administrator->id,
            'notes' => 'Weekend top-up',
        ]);

        foreach ($variants as $variant) {
            $restock->items()->create(['product_variant_id' => $variant->id, 'quantity_requested' => $this->between(2, 6)]);
        }
    }

    /**
     * Read every shelf from the ledger.
     */
    private function refreshShelves(): void
    {
        $this->shelves = array_fill_keys($this->outlets->pluck('id')->all(), []);

        $rows = ProductStockMovement::query()
            ->selectRaw('outlet_id, product_variant_id, SUM(quantity) as units')
            ->groupBy('outlet_id', 'product_variant_id')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            if (isset($this->shelves[$row->outlet_id])) {
                $this->shelves[$row->outlet_id][$row->product_variant_id] = (int) $row->units;
            }
        }
    }

    /**
     * Units on open pre-orders due on a day, by variant id.
     *
     * @return array<int, int>
     */
    private function orderedUnits(CarbonImmutable $day, ?Outlet $outlet = null): array
    {
        return Order::query()
            ->whereIn('status', [OrderStatus::Pending, OrderStatus::Confirmed, OrderStatus::Preparing, OrderStatus::Ready])
            ->whereBetween('pickup_at', [$day, $day->endOfDay()])
            ->when($outlet !== null, fn ($query) => $query->where('outlet_id', $outlet->id))
            ->with('items')
            ->get()
            ->flatMap->items
            ->groupBy('product_variant_id')
            ->map(fn (Collection $items): int => (int) $items->sum('quantity'))
            ->all();
    }

    /**
     * How much of the walk-in trade a counter takes.
     */
    private function share(Outlet $outlet): float
    {
        $others = max(1, $this->outlets->count() - 1);

        return $outlet->is_main_branch
            ? ($this->outlets->count() === 1 ? 1.0 : 0.45)
            : 0.55 / $others;
    }

    /**
     * A whole number of units around an expected figure.
     */
    private function draw(float $expected): int
    {
        $expected *= 0.8 + 0.4 * mt_rand() / mt_getrandmax();
        $whole = (int) floor($expected);

        return $whole + ($this->chance($expected - $whole) ? 1 : 0);
    }

    private function chance(float $probability): bool
    {
        return mt_rand() / mt_getrandmax() < $probability;
    }

    private function between(int $min, int $max): int
    {
        return mt_rand($min, $max);
    }

    /**
     * @template TValue
     *
     * @param  Collection<int, TValue>  $items
     * @return TValue
     */
    private function pick(Collection $items): mixed
    {
        return $items->values()[mt_rand(0, $items->count() - 1)];
    }
}
