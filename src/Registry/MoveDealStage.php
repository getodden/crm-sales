<?php

declare(strict_types=1);

namespace Odden\Sales\Registry;

use Odden\Core\Registry\ActionFailedException;
use Odden\Core\Registry\ActionResult;
use Odden\Core\Registry\Actions\BaseAction;
use Odden\Core\Registry\Actor;
use Odden\Sales\Actions\ChangeDealStageAction;
use Odden\Sales\Exceptions\StageRequirementException;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\PipelineStage;

class MoveDealStage extends BaseAction
{
    public static function name(): string
    {
        return 'deals.move_stage';
    }

    public static function description(): string
    {
        return 'Move a deal to another stage of its pipeline. Moving to a closed stage marks the deal won or lost. Use deals.search first to get the deal id and the stage ids. A stage can refuse the move if the deal lacks something it requires (a contact, a quote).';
    }

    public static function rules(): array
    {
        return [
            'deal_id' => ['required', 'integer'],
            'stage_id' => ['required', 'integer'],
            'lost_reason' => ['nullable', 'string', 'max:255'],
            'lost_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function sensitive(): array
    {
        return ['lost_notes'];
    }

    public function handle(Actor $actor, array $input): ActionResult
    {
        $deal = Deal::query()->find((int) $input['deal_id']);

        if ($deal === null) {
            throw new ActionFailedException('There is no deal with that id.');
        }

        $stage = PipelineStage::query()->find((int) $input['stage_id']);

        // A stage of another pipeline would silently move the deal there; the caller asked for a stage of this one.
        if ($stage === null || $stage->pipeline_id !== $deal->pipeline_id) {
            throw new ActionFailedException('That stage does not belong to the deal\'s pipeline.');
        }

        try {
            $moved = app(ChangeDealStageAction::class)->execute(
                $deal,
                $stage,
                is_numeric($actor->userId) ? (int) $actor->userId : null,
                $input['lost_reason'] ?? null,
                $input['lost_notes'] ?? null,
            );
        } catch (StageRequirementException $e) {
            throw new ActionFailedException($e->getMessage(), previous: $e);
        }

        return ActionResult::completed(
            ['id' => $moved->id, 'stage' => $stage->name, 'status' => $moved->status->value],
            null,
            $moved->getMorphClass(),
            $moved->id,
        );
    }
}
