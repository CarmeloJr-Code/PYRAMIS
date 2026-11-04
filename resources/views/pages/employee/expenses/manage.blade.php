<?php

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Outlet;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

new #[Title('Record expense')] class extends Component {
    use WithFileUploads;

    #[Locked]
    public ?int $expenseId = null;

    /**
     * Whether the expense being corrected already has a receipt on file. The
     * photo itself stays in the database; only the receipt route reads it.
     */
    #[Locked]
    public bool $hasReceipt = false;

    /** @var TemporaryUploadedFile|null */
    public $receipt = null;

    public ?int $expense_category_id = null;

    public ?int $outlet_id = null;

    public string $amount = '';

    public string $spent_on = '';

    public string $description = '';

    /**
     * Mount the component for either a new or an existing expense.
     */
    public function mount(?Expense $expense = null): void
    {
        Gate::authorize('access-expenses');

        if ($expense?->exists) {
            // Changing one already filed is oversight, not recording.
            Gate::authorize('manage-expenses');

            $this->expenseId = $expense->id;
            $this->expense_category_id = $expense->expense_category_id;
            $this->outlet_id = $expense->outlet_id;
            $this->amount = (string) $expense->amount;
            $this->spent_on = $expense->spent_on->toDateString();
            $this->description = $expense->description;
            $this->hasReceipt = $expense->hasReceipt();

            return;
        }

        $this->spent_on = now()->toDateString();
        $this->outlet_id = $this->outlets->first()?->id;
    }

    /**
     * The locations an expense can belong to.
     *
     * @return Collection<int, Outlet>
     */
    #[Computed]
    public function outlets(): Collection
    {
        return Outlet::query()
            ->active()
            ->orderByDesc('is_main_branch')
            ->orderBy('name')
            ->get();
    }

    /**
     * The headings on offer. A retired one stays selectable while it is the one
     * already on the expense being corrected.
     *
     * @return Collection<int, ExpenseCategory>
     */
    #[Computed]
    public function categories(): Collection
    {
        return ExpenseCategory::query()
            ->where(fn ($query) => $query
                ->where('is_active', true)
                ->orWhere('id', $this->expense_category_id ?? 0))
            ->orderBy('name')
            ->get();
    }

    /**
     * File the expense.
     */
    public function save(): void
    {
        Gate::authorize($this->expenseId === null ? 'access-expenses' : 'manage-expenses');

        // Livewire skips the TrimStrings middleware, so trim here.
        $this->description = trim($this->description);

        $validated = $this->validate([
            'expense_category_id' => [
                'required', 'integer',
                // A retired heading cannot be chosen for something new, but may
                // stay on an expense that already carries it.
                Rule::exists('expense_categories', 'id')->where(
                    fn ($query) => $this->expenseId === null
                        ? $query->where('is_active', true)
                        : $query,
                ),
            ],
            'outlet_id' => [
                'required', 'integer',
                Rule::exists('outlets', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:99999999.99'],
            // Money cannot have been spent in the future.
            'spent_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'description' => ['required', 'string', 'max:255'],
            // Proof for whoever checks the figures. Asked of every new expense;
            // a correction keeps the one on file unless another is attached,
            // and one filed before receipts were asked can be corrected without.
            'receipt' => [
                Rule::requiredIf($this->expenseId === null),
                'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096',
            ],
        ], [
            'receipt.required' => __('Attach a photo or screenshot of the receipt.'),
            'receipt.max' => __('The receipt must be 4 MB or smaller.'),
        ]);

        $expense = $this->expenseId === null
            ? new Expense(['recorded_by' => Auth::id()])
            : Expense::findOrFail($this->expenseId);

        // recorded_by is not rewritten on an edit: the record of who filed it
        // is part of the audit trail, not a field the corrector inherits.
        $expense->fill([
            'expense_category_id' => $validated['expense_category_id'],
            'outlet_id' => $validated['outlet_id'],
            'amount' => $validated['amount'],
            'spent_on' => $validated['spent_on'],
            'description' => $validated['description'],
        ]);

        if ($this->receipt !== null) {
            $expense->fill([
                'receipt' => base64_encode($this->receipt->get()),
                'receipt_mime' => $this->receipt->getMimeType(),
            ]);
        }

        $expense->save();

        Flux::toast(variant: 'success', text: __('Expense saved.'));

        $this->redirectRoute('employee.expenses.index', navigate: true);
    }
}; ?>

<section class="w-full max-w-2xl">
    <flux:heading size="xl" level="1">
        {{ $expenseId === null ? __('Record expense') : __('Edit expense') }}
    </flux:heading>
    <flux:text class="mt-2">{{ __('Money that left the business, filed against the place it belongs to.') }}</flux:text>

    <flux:separator variant="subtle" class="my-6" />

    @if ($this->categories->isEmpty())
        <flux:callout icon="tag">
            <flux:callout.heading>{{ __('No categories yet') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Every expense needs a category. A manager has to add the categories first.') }}</flux:callout.text>
        </flux:callout>
    @else
        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:input wire:model="description" :label="__('What it was')" placeholder="{{ __('Flour delivery') }}" maxlength="255" required autofocus />

            <flux:input
                wire:model="amount"
                :label="__('Amount')"
                type="number"
                step="0.01"
                min="0.01"
                max="99999999.99"
                inputmode="decimal"
                x-on:keydown="['e', 'E', '+', '-', ','].includes($event.key) && $event.preventDefault()"
                x-on:input="if ($el.value > 99999999.99) { $el.value = 99999999.99; $el.dispatchEvent(new Event('input')) }"
                required
            />

            <flux:select wire:model="expense_category_id" :label="__('Category')" required>
                <flux:select.option value="">{{ __('Choose a category') }}</flux:select.option>
                @foreach ($this->categories as $category)
                    <flux:select.option :value="$category->id">
                        {{ $category->name }}{{ $category->is_active ? '' : ' · '.__('retired') }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="outlet_id" :label="__('Location')" required>
                <flux:select.option value="">{{ __('Choose a location') }}</flux:select.option>
                @foreach ($this->outlets as $outlet)
                    <flux:select.option :value="$outlet->id">
                        {{ $outlet->name }}{{ $outlet->is_main_branch ? ' · '.__('Main branch') : '' }}
                    </flux:select.option>
                @endforeach
            </flux:select>

            <flux:input wire:model="spent_on" :label="__('Date spent')" type="date" :max="now()->toDateString()" required />

            {{-- A phone photo is several megabytes and is kept in the database,
                 so the browser shrinks it to 1600px of JPEG before it is sent:
                 still legible, a few hundred kilobytes. What it cannot decode
                 goes up as it is, and the rules in save() decide. Not
                 capture="environment": that would open the camera only, and a
                 screenshot of an e-receipt is as good as a photo. --}}
            <div
                class="flex flex-col gap-2"
                x-data="{
                    uploading: false,
                    async pick(event) {
                        const original = event.target.files[0];

                        if (! original) {
                            return;
                        }

                        const file = await this.shrink(original).catch(() => original);
                        const done = () => this.uploading = false;

                        this.uploading = true;
                        this.$wire.upload('receipt', file, done, done);
                    },
                    shrink(file) {
                        return new Promise((resolve, reject) => {
                            const image = new Image();
                            const url = URL.createObjectURL(file);

                            image.onload = () => {
                                const scale = Math.min(1, 1600 / Math.max(image.naturalWidth, image.naturalHeight));
                                const canvas = document.createElement('canvas');
                                canvas.width = Math.round(image.naturalWidth * scale);
                                canvas.height = Math.round(image.naturalHeight * scale);

                                const context = canvas.getContext('2d');
                                context.fillStyle = '#fff';
                                context.fillRect(0, 0, canvas.width, canvas.height);
                                context.drawImage(image, 0, 0, canvas.width, canvas.height);
                                URL.revokeObjectURL(url);

                                canvas.toBlob(
                                    (blob) => blob ? resolve(new File([blob], 'receipt.jpg', { type: 'image/jpeg' })) : reject(),
                                    'image/jpeg',
                                    0.8,
                                );
                            };
                            image.onerror = () => {
                                URL.revokeObjectURL(url);
                                reject();
                            };
                            image.src = url;
                        });
                    },
                }"
            >
                <label for="receipt" class="text-sm font-medium">
                    {{ $hasReceipt ? __('Replace the receipt') : __('Receipt') }}
                </label>

                @if ($hasReceipt && $receipt === null)
                    <a href="{{ route('employee.expenses.receipt', $expenseId) }}" target="_blank" rel="noopener" class="block self-start">
                        <img
                            src="{{ route('employee.expenses.receipt', $expenseId) }}"
                            alt="{{ __('The receipt on file') }}"
                            class="max-h-48 rounded-lg border border-zinc-200 dark:border-zinc-700"
                        >
                        <span class="text-xs text-zinc-500">{{ __('Receipt on file — open full size') }}</span>
                    </a>
                @endif

                <input
                    id="receipt"
                    type="file"
                    accept="image/png,image/jpeg,image/webp"
                    x-on:change="pick($event)"
                    class="text-sm text-zinc-600 file:me-3 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-zinc-700 hover:file:bg-zinc-200 dark:text-zinc-300 dark:file:bg-zinc-700 dark:file:text-zinc-100"
                >
                <p class="text-xs text-zinc-500">
                    {{ $expenseId === null
                        ? __('A photo or screenshot of the receipt, so the amount can be checked. PNG, JPG or WebP.')
                        : __('Optional. Leave empty to keep the receipt on file.') }}
                </p>
                <p x-show="uploading" x-cloak class="text-xs text-zinc-500">{{ __('Uploading…') }}</p>

                @if ($receipt !== null && $receipt->isPreviewable())
                    <img
                        src="{{ $receipt->temporaryUrl() }}"
                        alt="{{ __('The receipt being attached') }}"
                        class="max-h-48 self-start rounded-lg border border-zinc-200 dark:border-zinc-700"
                    >
                @endif

                @error('receipt')
                    <flux:text class="text-danger">{{ $message }}</flux:text>
                @enderror
            </div>

            <div class="flex items-center gap-3">
                <flux:button variant="primary" type="submit" wire:loading.attr="disabled" wire:target="receipt,save">{{ __('Save expense') }}</flux:button>
                <flux:button variant="ghost" :href="route('employee.expenses.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    @endif
</section>
