<?php

namespace App\Http\Controllers;

use App\Models\ProductPhoto;
use Symfony\Component\HttpFoundation\Response;

/**
 * A menu-card photo a manager uploaded, for the storefront and the workspace.
 */
class ProductPhotoController extends Controller
{
    /**
     * Serve the stored photo.
     *
     * Public, like the menu it is shown on. A new upload is a new photo with a
     * new address, so whatever is served here never changes and may be cached
     * for good.
     */
    public function __invoke(ProductPhoto $productPhoto): Response
    {
        $bytes = base64_decode($productPhoto->data, strict: true);

        abort_if($bytes === false, 404);

        return response($bytes, 200, [
            'Content-Type' => $productPhoto->mime,
            'Content-Disposition' => 'inline; filename="product-photo-'.$productPhoto->id.'"',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
