<?php

declare(strict_types=1);

namespace Odden\Sales\Support;

/**
 * The business's own logo and accent colour on the booking and quote pages, when the app sets them:
 * config('odden-core.branding.logo_url') and config('odden-core.branding.accent_color'). Unset, the pages keep
 * their own look.
 *
 * Only a #rrggbb colour is used, and the CSS is built here from its three numbers, so a setting can never add CSS
 * of its own. Only an http(s) address or a path on this host is used as the logo. Checking that the colour is
 * readable on white is left to whoever sets it.
 */
final class PageBranding
{
    /**
     * The accent colour as #rrggbb (lower case), or null when none is set or the value is not one.
     */
    public static function accent(): ?string
    {
        $value = config('odden-core.branding.accent_color');

        return is_string($value) && preg_match('/^#[0-9a-f]{6}$/i', $value) === 1 ? strtolower($value) : null;
    }

    /**
     * The logo's address, or null when none is set or it is not an http(s) address or a path on this host.
     */
    public static function logoUrl(): ?string
    {
        $value = config('odden-core.branding.logo_url');

        if (! is_string($value) || $value === '' || preg_match('/[\s"\'<>\\\\]/', $value) === 1) {
            return null;
        }

        if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
            return $value;
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return in_array($scheme, ['https', 'http'], true) && filter_var($value, FILTER_VALIDATE_URL) !== false ? $value : null;
    }

    /**
     * The colour as CSS variables the pages use in place of their own accent (--odden-accent, and a darker
     * --odden-accent-hover), or '' when there is none.
     */
    public static function css(): string
    {
        $accent = self::accent();

        return $accent === null ? '' : ":root{--odden-accent:{$accent};--odden-accent-hover:".self::darker($accent).'}';
    }

    /**
     * $color with each channel 18% of the way to black.
     */
    private static function darker(string $color): string
    {
        $channels = sscanf($color, '#%02x%02x%02x') ?? [0, 0, 0];

        return '#'.implode('', array_map(fn (mixed $channel): string => sprintf('%02x', (int) round((int) $channel * 0.82)), $channels));
    }
}
