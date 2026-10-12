<?php

declare(strict_types=1);

namespace Odden\Sales\Tests;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Odden\Core\Models\Contact;
use Odden\Sales\Models\SalesSequence;
use Odden\Sales\Models\SalesSequenceEnrollment;

class EnrollmentUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private function sequence(): SalesSequence
    {
        return SalesSequence::create([
            'name' => 'Outbound',
            'steps' => [['step' => 1, 'type' => 'call', 'delay_days' => 0, 'title' => 'Call']],
            'is_active' => true,
        ]);
    }

    public function test_a_contact_can_be_enrolled_in_a_sequence_only_once(): void
    {
        $sequence = $this->sequence();
        $contact = Contact::factory()->create();
        SalesSequenceEnrollment::create(['sequence_id' => $sequence->id, 'contact_id' => $contact->id]);

        $this->expectException(UniqueConstraintViolationException::class);

        SalesSequenceEnrollment::create(['sequence_id' => $sequence->id, 'contact_id' => $contact->id]);
    }

    public function test_the_migration_removes_existing_duplicates_first(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $this->markTestSkipped('MySQL commits the test transaction on schema changes.');
        }

        /** @var Migration $migration */
        $migration = require dirname(__DIR__).'/database/migrations/2026_01_02_000009_add_unique_contact_index_to_odden_sales_sequence_enrollments.php';
        $migration->down();

        $sequence = $this->sequence();
        $contact = Contact::factory()->create();
        $other = Contact::factory()->create();
        $row = fn (Contact $contact, string $status, int $step): int => SalesSequenceEnrollment::create([
            'sequence_id' => $sequence->id, 'contact_id' => $contact->id, 'status' => $status, 'current_step' => $step,
        ])->id;

        $row($contact, 'active', 3);
        $finished = $row($contact, 'completed', 1);
        $row($contact, 'active', 1);
        $alone = $row($other, 'active', 1);

        $migration->up();

        $this->assertEqualsCanonicalizing([$finished, $alone], SalesSequenceEnrollment::query()->pluck('id')->all());
    }
}
