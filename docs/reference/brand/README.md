# Brand assets (source)

Logo originals, working files, and high-resolution exports for **Purple Yam Malaybalay** (storefront) and **PYRAMIS** (employee workspace).

**Status: source material.** Nothing here is served to the browser. These are the masters that web-ready assets are exported *from*.

## What belongs here

- Vector/layered sources: `.ai`, `.svg`, `.psd`, `.fig` exports
- High-resolution raster exports kept for reprinting or re-cropping
- Wordmarks, icon-only marks, and any horizontal/stacked lockup variants
- Signage, packaging, or print artwork supplied by the business

## Where the *web-ready* versions go instead

| Asset | Destination |
|---|---|
| Primary mark rendered in the UI | inline SVG inside `resources/views/components/app-logo-icon.blade.php` — keep `fill="currentColor"` so it inherits theme color |
| Favicon / touch icon | overwrite `public/favicon.ico`, `public/favicon.svg`, `public/apple-touch-icon.png` (already wired in `resources/views/partials/head.blade.php`) |
| A raster the app genuinely needs (og:image, storefront `<img>`) | `resources/images/`, referenced through Vite — create that folder only when a slice actually needs it |

## Related

The color palette is documented separately in `../design-vibe.md` (full ramp and color roles), `../palette.txt` (Coolors export), and `../assets/palette.png` (swatch board).

Filenames: lowercase-kebab, e.g. `purple-yam-wordmark-horizontal.ai`.
