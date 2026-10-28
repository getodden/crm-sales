{{-- The business's accent colour, when the app sets one (see PageBranding), as the CSS variables the page's own rules fall back from. --}}
@php($brandingCss = \Odden\Sales\Support\PageBranding::css())
@if($brandingCss !== '')
    <style>{!! $brandingCss !!}</style>
@endif
