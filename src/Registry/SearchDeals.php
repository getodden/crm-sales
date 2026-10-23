<?php

declare(strict_types=1);

namespace Odden\Sales\Registry;

use Odden\Core\Registry\ActionResult;
use Odden\Core\Registry\Actions\BaseAction;
use Odden\Core\Registry\Actor;
use Odden\Core\Registry\Risk;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\PipelineStage;

class SearchDeals extends BaseAction
{
    public const int LIMIT = 25;

    public static function name(): string
    {
        return 'deals.search';
    }

    public static function description(): string
    {
        return 'Find deals by name. Returns each deal\'s id, status, current stage and the stages of its pipeline (with their ids), which is what deals.move_stage needs. Returns at most '.self::LIMIT.' matches.';
    }

    public static function rules(): array
    {
        return [
            'query' => ['required', 'string', 'min:2', 'max:100'],
            'status' => ['nullable', 'string', 'in:open,won,lost'],
        ];
    }

    public function risk(): Risk
    {
        return Risk::Read;
    }

    public function handle(Actor $actor, array $input): ActionResult
    {
        $deals = Deal::query()
            ->with(['stage', 'pipeline.stages'])
            ->where('name', 'like', '%'.addcslashes((string) $input['query'], '%_\\').'%')
            ->when(isset($input['status']), fn ($q) => $q->where('status', $input['status']))
            ->orderBy('id')
            ->limit(self::LIMIT)
            ->get();

        return ActionResult::completed([
            'deals' => $deals->map(fn (Deal $deal): array => [
                'id' => $deal->id,
                'name' => $deal->name,
                'amount' => $deal->amount,
                'currency' => $deal->currency,
                'status' => $deal->status->value,
                'stage' => ['id' => $deal->stage->id, 'name' => $deal->stage->name],
                'pipeline_stages' => $deal->pipeline->stages->map(fn (PipelineStage $s): array => ['id' => $s->id, 'name' => $s->name])->all(),
            ])->all(),
        ], $deals->isEmpty() ? 'No deals matched.' : null);
    }
}
