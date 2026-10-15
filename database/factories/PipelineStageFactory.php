<?php

declare(strict_types=1);

namespace Odden\Sales\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\PipelineStage;

/**
 * @extends Factory<PipelineStage>
 */
class PipelineStageFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<PipelineStage>
     */
    protected $model = PipelineStage::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'pipeline_id' => Pipeline::factory(),
            'name' => ucwords(fake()->words(2, true)),
            // From the final name (an override included), and never a code the pipeline already has: (pipeline_id, code) is unique.
            'code' => fn (array $attributes): string => $this->uniqueCode((string) $attributes['name'], $attributes['pipeline_id'] ?? null),
            'probability' => fake()->randomElement([10, 20, 40, 60, 80]),
            'sort_order' => fake()->numberBetween(1, 10),
            'is_closed_won' => false,
            'is_closed_lost' => false,
        ];
    }

    /**
     * Codes this factory has handed out per pipeline in the current batch. count(n)->create() makes every model before it saves
     * any, so the database cannot see the earlier ones yet.
     *
     * @var array<int|string, list<string>>
     */
    private array $issued = [];

    /**
     * The stage's name as a code, with a number added when the pipeline already holds a stage with that code, or this batch
     * has already given it to another.
     */
    private function uniqueCode(string $name, mixed $pipelineId): string
    {
        $base = Str::slug($name, '_');
        $base = $base === '' ? 'stage' : $base;

        // A stage that is only made, not saved, belongs to no pipeline yet and cannot collide.
        if (! is_int($pipelineId) && ! (is_string($pipelineId) && ctype_digit($pipelineId))) {
            return $base;
        }

        $code = $base;
        $suffix = 1;

        while (in_array($code, $this->issued[$pipelineId] ?? [], true)
            || PipelineStage::query()->withoutGlobalScopes()->where('pipeline_id', $pipelineId)->where('code', $code)->exists()) {
            $code = $base.'_'.(++$suffix);
        }

        $this->issued[$pipelineId][] = $code;

        return $code;
    }

    /**
     * Indicate that the stage is closed won.
     */
    public function closedWon(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Closed Won',
            'code' => 'closed_won',
            'probability' => 100,
            'is_closed_won' => true,
            'is_closed_lost' => false,
        ]);
    }

    /**
     * Indicate that the stage is closed lost.
     */
    public function closedLost(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Closed Lost',
            'code' => 'closed_lost',
            'probability' => 0,
            'is_closed_won' => false,
            'is_closed_lost' => true,
        ]);
    }
}
