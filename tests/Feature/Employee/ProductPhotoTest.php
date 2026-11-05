<?php

namespace Tests\Feature\Employee;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductPhoto;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductPhotoTest extends TestCase
{
    use RefreshDatabase;

    protected Product $product;

    protected ProductVariant $round;

    protected ProductVariant $heart;

    protected function setUp(): void
    {
        parent::setUp();

        // Ube Cake ships with built-in photos, of the cake and of its heart.
        $this->product = Product::factory()
            ->for(Category::factory()->create(['name' => 'Ube Cakes', 'slug' => 'ube-cakes']))
            ->create(['name' => 'Ube Cake', 'slug' => 'ube-cake']);

        $this->round = ProductVariant::factory()->for($this->product)->create(['name' => 'Round (7x3)']);
        $this->heart = ProductVariant::factory()->for($this->product)->create(['name' => 'Heart (6" dia)']);
    }

    /**
     * One of the photo files under tests/Fixtures/product-photos, as an upload.
     */
    protected function fixture(string $name, string $as = 'card.jpg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($as, (string) file_get_contents(base_path("tests/Fixtures/product-photos/{$name}")));
    }

    /**
     * The edit page of the product, as its administrator.
     */
    protected function editing(): Testable
    {
        return Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.products.manage', ['product' => $this->product]);
    }

    public function test_an_administrator_uploads_the_products_own_photo_for_the_menu(): void
    {
        $this->editing()
            ->set('upload', $this->fixture('card.jpg'))
            ->call('savePhoto')
            ->assertHasNoErrors();

        $photo = ProductPhoto::sole();

        $this->assertSame($this->product->id, $photo->product_id);
        $this->assertNull($photo->product_variant_id);
        $this->assertSame('image/jpeg', $photo->mime);
        $this->assertSame(file_get_contents(base_path('tests/Fixtures/product-photos/card.jpg')), base64_decode($photo->data));

        // The round has no built-in photo of its own, so it shows the upload;
        // the heart keeps the photo taken of it.
        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee($photo->url(), false)
            ->assertSee(asset('images/products/ube-cake-heart.webp'), false)
            ->assertDontSee(asset('images/products/ube-cake.webp'), false);

        $this->get(route('products.show', ['product' => $this->product, 'size' => $this->round->id]))
            ->assertOk()
            ->assertSee($photo->url(), false);
    }

    public function test_a_webp_photo_is_accepted(): void
    {
        $this->editing()
            ->set('upload', $this->fixture('card.webp', 'card.webp'))
            ->call('savePhoto')
            ->assertHasNoErrors();

        $this->assertSame('image/webp', ProductPhoto::sole()->mime);
    }

    public function test_a_sizes_photo_shows_on_that_sizes_card_only_ahead_of_its_built_in_one(): void
    {
        $this->editing()
            ->set('upload', $this->fixture('card.jpg'))
            ->call('savePhoto', $this->heart->id)
            ->assertHasNoErrors();

        $photo = ProductPhoto::sole();

        $this->assertSame($this->heart->id, $photo->product_variant_id);

        $this->get(route('products.show', ['product' => $this->product, 'size' => $this->heart->id]))
            ->assertOk()
            ->assertSee($photo->url(), false)
            ->assertDontSee('images/products/ube-cake-heart.webp', false);

        $this->get(route('products.show', ['product' => $this->product, 'size' => $this->round->id]))
            ->assertOk()
            ->assertSee(asset('images/products/ube-cake.webp'), false)
            ->assertDontSee($photo->url(), false);
    }

    /**
     * Uploads that miss the specification, and what they are told.
     *
     * @return array<string, array{0: string, 1: string, 2: int|null, 3: string}>
     */
    public static function refusedProvider(): array
    {
        return [
            'the wrong size' => ['wrong-size.jpg', 'card.jpg', null, 'The photo must be exactly 1200 × 900 pixels.'],
            'portrait' => ['portrait.jpg', 'card.jpg', null, 'The photo must be exactly 1200 × 900 pixels.'],
            'a PNG' => ['card.png', 'card.png', null, 'The photo must be a JPG or WebP image.'],
            'a PNG named as a JPG' => ['card.png', 'card.jpg', null, 'The photo must be a JPG or WebP image.'],
            'over 1 MB' => ['card.jpg', 'card.jpg', 2048, 'The photo must be 1 MB or smaller.'],
        ];
    }

    #[DataProvider('refusedProvider')]
    public function test_a_photo_that_misses_the_specification_is_refused(string $fixture, string $as, ?int $kilobytes, string $message): void
    {
        $upload = $this->fixture($fixture, $as);

        if ($kilobytes !== null) {
            $upload->size($kilobytes);
        }

        $this->editing()
            ->set('upload', $upload)
            ->call('savePhoto', $this->heart->id)
            ->assertHasErrors('photo.'.$this->heart->id)
            ->assertSee($message)
            ->assertSet('upload', null);

        $this->assertDatabaseCount('product_photos', 0);
    }

    public function test_a_file_that_is_not_an_image_is_refused(): void
    {
        $this->editing()
            ->set('upload', UploadedFile::fake()->createWithContent('card.jpg', 'not a photo at all'))
            ->call('savePhoto')
            ->assertHasErrors('photo.product')
            ->assertSee('The photo must be a JPG or WebP image.');

        $this->assertDatabaseCount('product_photos', 0);
    }

    public function test_saving_without_a_photo_is_refused(): void
    {
        $this->editing()
            ->call('savePhoto')
            ->assertHasErrors('photo.product')
            ->assertSee('Choose a photo to upload.');

        $this->assertDatabaseCount('product_photos', 0);
    }

    public function test_a_new_photo_replaces_the_old_one_whose_address_stops_answering(): void
    {
        $old = ProductPhoto::factory()->forSize($this->heart)->create();

        $this->editing()
            ->set('upload', $this->fixture('card.jpg'))
            ->call('savePhoto', $this->heart->id)
            ->assertHasNoErrors();

        $this->assertSame(1, ProductPhoto::where('product_variant_id', $this->heart->id)->count());
        $this->assertModelMissing($old);

        $this->get($old->url())->assertNotFound();
    }

    public function test_removing_a_photo_puts_the_built_in_one_back(): void
    {
        $photo = ProductPhoto::factory()->forSize($this->heart)->create();
        $mine = ProductPhoto::factory()->for($this->product)->create();

        $this->editing()
            ->assertSee($photo->url(), false)
            ->call('removePhoto', $this->heart->id)
            ->assertDontSee($photo->url(), false);

        $this->assertModelMissing($photo);
        $this->assertModelExists($mine);

        $this->get(route('products.show', ['product' => $this->product, 'size' => $this->heart->id]))
            ->assertOk()
            ->assertSee(asset('images/products/ube-cake-heart.webp'), false);
    }

    public function test_a_size_of_another_product_cannot_be_given_a_photo(): void
    {
        $elsewhere = ProductVariant::factory()->create();

        $this->editing()
            ->set('upload', $this->fixture('card.jpg'))
            ->call('savePhoto', $elsewhere->id)
            ->assertNotFound();

        $this->assertDatabaseCount('product_photos', 0);
    }

    public function test_only_administrators_may_manage_photos(): void
    {
        foreach ([User::factory()->cashier()->create(), User::factory()->baker()->create()] as $user) {
            Livewire::actingAs($user)
                ->test('pages::employee.products.manage', ['product' => $this->product])
                ->assertForbidden();
        }

        $this->assertDatabaseCount('product_photos', 0);
    }

    public function test_each_card_says_where_its_picture_comes_from(): void
    {
        ProductPhoto::factory()->for($this->product)->create();

        // The product's upload; the heart's built-in photo; the round, with
        // nothing of its own, showing the product's.
        $this->editing()->assertSeeInOrder([
            'The product', 'Uploaded',
            'Round (7x3)', 'Product&#039;s photo',
            'Heart (6&quot; dia)', 'Built-in',
        ], false);
    }

    public function test_a_new_product_is_saved_before_it_takes_photos(): void
    {
        Livewire::actingAs(User::factory()->administrator()->create())
            ->test('pages::employee.products.manage')
            ->assertSee('Save the product first, then add its photos.')
            ->assertDontSee('Remove photo');
    }

    public function test_a_sizes_photo_goes_with_the_size_and_a_products_with_the_product(): void
    {
        $heartPhoto = ProductPhoto::factory()->forSize($this->heart)->create();
        $productPhoto = ProductPhoto::factory()->for($this->product)->create();

        $this->heart->delete();

        $this->assertModelMissing($heartPhoto);
        $this->assertModelExists($productPhoto);

        $this->product->delete();

        $this->assertModelMissing($productPhoto);
    }

    public function test_the_photo_is_served_to_anyone_and_cached_for_good(): void
    {
        $bytes = (string) file_get_contents(base_path('tests/Fixtures/product-photos/card.webp'));
        $photo = ProductPhoto::factory()->for($this->product)->create(['mime' => 'image/webp', 'data' => base64_encode($bytes)]);

        $response = $this->get($photo->url())
            ->assertOk()
            ->assertHeader('Content-Type', 'image/webp')
            ->assertHeaderMissing('Set-Cookie');

        $this->assertSame($bytes, $response->getContent());
        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('immutable', (string) $response->headers->get('Cache-Control'));
    }

    public function test_an_unknown_photo_is_not_found(): void
    {
        $this->get(route('product-photos.show', 999))->assertNotFound();
    }
}
