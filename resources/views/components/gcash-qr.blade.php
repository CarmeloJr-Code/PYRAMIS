{{-- The shop's GCash QR. A placeholder until the real code is supplied:
     replace public/images/gcash-qr.svg and nothing else changes. --}}
<img
    src="{{ asset('images/gcash-qr.svg') }}"
    alt="{{ __('GCash QR code') }}"
    width="240"
    height="240"
    {{ $attributes->merge(['class' => 'size-48 rounded-xl border border-snow-200 bg-white sm:size-56']) }}
>
