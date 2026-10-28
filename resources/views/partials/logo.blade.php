{{-- The business's logo, when the app sets one (see PageBranding), named by the business's name. --}}
@php($logoUrl = \Odden\Sales\Support\PageBranding::logoUrl())
@if($logoUrl !== null)
    <img src="{{ $logoUrl }}" alt="{{ config('app.name') }}" style="display:block;height:2rem;width:auto;max-width:12rem;object-fit:contain;margin-bottom:1.25rem">
@endif
