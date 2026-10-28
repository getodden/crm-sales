<?php

declare(strict_types=1);

namespace Odden\Sales\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Sales\Enums\QuoteStatus;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\Quote;
use Odden\Sales\Models\SalesMeetingLink;
use Odden\Sales\Support\PageBranding;
use Odden\Sales\Tests\Fixtures\User;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The booking and quote pages take the business's logo and accent colour from odden-core.branding.*. Unset, they
 * look as they always did; a value that is not a plain colour or address is ignored.
 */
class PageBrandingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<string>
     */
    private function pages(): array
    {
        $user = User::factory()->create(['name' => 'Beth Caldwell']);
        $link = SalesMeetingLink::query()->create(['user_id' => $user->id, 'slug' => 'beth', 'title' => 'Discovery call', 'duration_minutes' => 30, 'working_hours' => ['monday' => ['09:00-10:00']], 'is_active' => true]);

        $pipeline = Pipeline::factory()->withStages()->create();
        $deal = Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $pipeline->stages->first()->id]);
        $quote = Quote::factory()->create(['deal_id' => $deal->id, 'status' => QuoteStatus::Sent, 'public_token' => 'branding-quote-token']);

        return ["/meet/{$link->slug}", "/quotes/{$quote->public_token}"];
    }

    public function test_pages_show_the_logo_and_accent_colour_when_set(): void
    {
        $pages = $this->pages();
        config(['app.name' => 'Acme', 'odden-core.branding.logo_url' => 'https://acme.example.com/logo.png', 'odden-core.branding.accent_color' => '#0F766E']);

        foreach ($pages as $url) {
            $this->get($url)
                ->assertSuccessful()
                ->assertSee('<img src="https://acme.example.com/logo.png" alt="Acme"', false)
                ->assertSee('<style>:root{--odden-accent:#0f766e;--odden-accent-hover:#0c615a}</style>', false);
        }
    }

    public function test_pages_keep_their_own_look_when_nothing_is_set(): void
    {
        foreach ($this->pages() as $url) {
            $this->get($url)->assertSuccessful()->assertDontSee(':root{--odden-accent', false)->assertDontSee('<img src=', false);
        }
    }

    /**
     * @return array<string, array{0: mixed, 1: string}>
     */
    public static function unsafeValues(): array
    {
        return [
            'colour closes the style element' => ['#000000;}</style><script>alert(1)</script>', 'https://acme.example.com/logo.png'],
            'colour adds declarations' => ['#000000;background:url(https://evil.example)', 'https://acme.example.com/logo.png'],
            'named colour' => ['red', 'https://acme.example.com/logo.png'],
            'javascript logo' => ['#0f766e', 'javascript:alert(1)'],
            'logo breaks out of the attribute' => ['#0f766e', 'https://acme.example.com/logo.png" onerror="alert(1)'],
            'protocol-relative logo' => ['#0f766e', '//evil.example/logo.png'],
        ];
    }

    #[DataProvider('unsafeValues')]
    public function test_a_value_that_is_not_plain_is_ignored(mixed $colour, string $logo): void
    {
        $pages = $this->pages();
        config(['odden-core.branding.accent_color' => $colour, 'odden-core.branding.logo_url' => $logo]);

        foreach ($pages as $url) {
            $this->get($url)->assertSuccessful()->assertDontSee('alert(1)', false)->assertDontSee('evil.example', false);
        }

        $this->assertTrue(PageBranding::accent() === null || PageBranding::logoUrl() === null);
    }
}
