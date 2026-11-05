<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Odden\Sales\Models\SalesSequenceEnrollment;

class UnenrollFromSequenceAction
{
    public function execute(SalesSequenceEnrollment $enrollment): SalesSequenceEnrollment
    {
        $enrollment->update([
            'status' => 'unenrolled',
            'next_step_due_at' => null,
        ]);

        return $enrollment;
    }
}
