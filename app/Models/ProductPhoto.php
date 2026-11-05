<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Closure;
use Database\Factories\ProductPhotoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;

/**
 * A menu-card photo a manager uploaded for a product, or for one size of it.
 *
 * Every photo meets one specification, so the cards are uniform: exactly
 * 1200 × 900 pixels, JPG or WebP, and no more than 1 MB. A photo is never
 * edited in place — a new upload is a new row — so its address can be cached
 * for good.
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property string $mime
 * @property string $data
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['product_id', 'product_variant_id', 'mime', 'data'])]
#[Hidden(['data'])]
class ProductPhoto extends Model
{
    /** @use HasFactory<ProductPhotoFactory> */
    use HasFactory;

    public const WIDTH = 1200;

    public const HEIGHT = 900;

    public const MAX_KILOBYTES = 1024;

    /**
     * Everything but the photo itself, which only the photo route needs.
     *
     * @var list<string>
     */
    public const SUMMARY_COLUMNS = ['id', 'product_id', 'product_variant_id', 'mime', 'created_at', 'updated_at'];

    /**
     * The rules an upload must pass to become a photo, stopping at the first
     * it fails so the manager is told one thing to fix.
     *
     * The file's name only proves what it claims to be — Livewire answers
     * "image" and "mimes" from the extension — so the bytes themselves must
     * read as a JPG or WebP picture too.
     *
     * @return list<string|Closure>
     */
    public static function rules(): array
    {
        return [
            'bail',
            'required',
            'file',
            'mimes:jpg,jpeg,webp',
            function (string $attribute, mixed $value, Closure $fail): void {
                if (! $value instanceof UploadedFile || self::detectedMime($value) === null) {
                    $fail(__('The photo must be a JPG or WebP image.'));
                }
            },
            'max:'.self::MAX_KILOBYTES,
            'dimensions:width='.self::WIDTH.',height='.self::HEIGHT,
        ];
    }

    /**
     * What the file's bytes say it is, if that is a JPG or a WebP picture.
     */
    public static function detectedMime(UploadedFile $file): ?string
    {
        $info = @getimagesize((string) $file->getRealPath());

        return match ($info[2] ?? null) {
            IMAGETYPE_JPEG => 'image/jpeg',
            IMAGETYPE_WEBP => 'image/webp',
            default => null,
        };
    }

    /**
     * What a refused upload is told, keyed for the given field.
     *
     * @return array<string, string>
     */
    public static function messages(string $field): array
    {
        return [
            "{$field}.required" => __('Choose a photo to upload.'),
            "{$field}.file" => __('The photo must be a JPG or WebP image.'),
            "{$field}.mimes" => __('The photo must be a JPG or WebP image.'),
            "{$field}.max" => __('The photo must be 1 MB or smaller.'),
            "{$field}.dimensions" => __('The photo must be exactly :width × :height pixels.', ['width' => self::WIDTH, 'height' => self::HEIGHT]),
        ];
    }

    /**
     * The specification in a sentence, for the upload form.
     */
    public static function specification(): string
    {
        return __('Exactly :width × :height pixels (4:3), JPG or WebP, 1 MB or smaller.', [
            'width' => self::WIDTH,
            'height' => self::HEIGHT,
        ]);
    }

    /**
     * The product this is a photo of.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * The size this is a photo of, if it is not the product's own.
     *
     * @return BelongsTo<ProductVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /**
     * Where the photo is served from.
     */
    public function url(): string
    {
        return route('product-photos.show', $this);
    }
}
