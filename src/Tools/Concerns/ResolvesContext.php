<?php

namespace Platform\Forecast\Tools\Concerns;

use Platform\Core\Contracts\ToolContext;
use Platform\Forecast\Models\ForecastPlan;
use Platform\Forecast\Models\ForecastPlanType;
use Platform\Forecast\Models\ForecastSnapshot;

trait ResolvesContext
{
    protected function teamId(ToolContext $context): ?int
    {
        $team = $context->team ?? ($context->user->currentTeam ?? null);

        return isset($team->id) ? (int) $team->id : null;
    }

    protected function userId(ToolContext $context): ?int
    {
        return isset($context->user->id) ? (int) $context->user->id : null;
    }

    protected function findPlan(string $uuid, int $teamId): ?ForecastPlan
    {
        return ForecastPlan::where('team_id', $teamId)->where('uuid', $uuid)->first();
    }

    /** Typ per uuid ODER key auflösen. */
    protected function findType(string $ref, int $teamId): ?ForecastPlanType
    {
        return ForecastPlanType::where('team_id', $teamId)
            ->where(fn ($q) => $q->where('uuid', $ref)->orWhere('key', $ref))
            ->first();
    }

    protected function findSnapshot(string $uuid, int $teamId): ?ForecastSnapshot
    {
        return ForecastSnapshot::where('team_id', $teamId)->where('uuid', $uuid)->first();
    }
}
