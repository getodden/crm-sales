<?php

declare(strict_types=1);

namespace Odden\Sales\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Models\Contact;
use Odden\Sales\Models\SalesMeetingBooking;
use Odden\Sales\Models\SalesMeetingLink;
use Odden\Sales\Services\MeetingAvailability;
use Odden\Sales\Tests\Fixtures\User;

class SalesMeetingSchedulerTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_view_public_scheduler_page(): void
    {
        $user = User::factory()->create(['name' => 'Beth Caldwell']);
        $link = SalesMeetingLink::query()->create([
            'user_id' => $user->id,
            'slug' => 'beth-caldwell-30min',
            'title' => '30 Min Product Strategy',
            'duration_minutes' => 30,
            'is_active' => true,
        ]);

        $response = $this->get("/meet/{$link->slug}");

        $response->assertSuccessful();
        $response->assertSee('Beth Caldwell');
        $response->assertSee('30 Min Product Strategy');
        $response->assertSee('Confirm Meeting');
    }

    public function test_can_book_meeting_and_create_contact_with_activity(): void
    {
        $user = User::factory()->create(['name' => 'Beth Caldwell']);
        $link = SalesMeetingLink::query()->create([
            'user_id' => $user->id,
            'slug' => 'beth-caldwell-demo',
            'title' => 'Product Demo Call',
            'duration_minutes' => 45,
            // Open every day so "tomorrow" is bookable whatever weekday the suite runs on.
            'working_hours' => array_fill_keys(MeetingAvailability::DAYS, ['09:00-17:00']),
            'is_active' => true,
        ]);

        $response = $this->post("/meet/{$link->slug}/book", [
            'name' => 'Clark Kent',
            'email' => 'clark@dailyplanet.com',
            'phone' => '+1 (555) 123-4567',
            'date' => now()->addDay()->toDateString(),
            'time' => '09:00', // 45-minute slots start at 09:00, 09:45, ... (14:00 is not on the grid)
            'notes' => 'Looking to migrate our entire newsroom workflow.',
        ]);

        $response->assertRedirect("/meet/{$link->slug}");
        $response->assertSessionHas('status');

        $contact = Contact::query()->whereEmail('clark@dailyplanet.com')->first();
        $this->assertNotNull($contact);
        $this->assertSame('Clark', $contact->first_name);
        $this->assertSame('Kent', $contact->last_name);
        $this->assertSame($user->id, $contact->owner_id);

        $this->assertDatabaseHas('odden_activities', [
            'subject_type' => $contact->getMorphClass(),
            'subject_id' => $contact->id,
            'type' => ActivityType::Meeting->value,
            'title' => 'Product Demo Call with Clark Kent',
        ]);
    }

    public function test_finding_the_first_open_day_reads_the_bookings_once(): void
    {
        $this->travelTo(now('UTC')->startOfDay()->setTime(6, 0));
        $user = User::factory()->create();
        $link = SalesMeetingLink::query()->create([
            'user_id' => $user->id,
            'slug' => 'one-slot-a-day',
            'title' => 'Daily slot',
            'duration_minutes' => 60,
            'timezone' => 'UTC',
            'working_hours' => array_fill_keys(MeetingAvailability::DAYS, ['09:00-10:00']),
            'is_active' => true,
        ]);

        // The next ten days are fully booked.
        foreach (range(0, 9) as $day) {
            $start = now('UTC')->startOfDay()->addDays($day)->setTime(9, 0);
            SalesMeetingBooking::query()->create([
                'uid' => "busy-{$day}",
                'meeting_link_id' => $link->id,
                'user_id' => $user->id,
                'invitee_name' => 'Busy',
                'invitee_email' => "busy{$day}@example.com",
                'starts_at' => $start,
                'ends_at' => $start->copy()->addHour(),
                'timezone' => 'UTC',
            ]);
        }

        DB::enableQueryLog();
        $first = app(MeetingAvailability::class)->firstAvailableDate($link);

        $this->assertSame(now('UTC')->addDays(10)->toDateString(), $first);
        $this->assertCount(1, DB::getQueryLog());
    }
}
