<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Models\ForecastDistributionPolicy;
use Platform\Forecast\Tools\Concerns\ResolvesContext;

class UpdatePlanTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    public function getName(): string
    {
        return 'forecast.plan.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /plans/{plan} – Aktualisiert eine Planung. Parameter: plan (uuid), name?, '
            .'distribution_policy? (uuid oder key; leerer String = auf Default zurücksetzen).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan' => ['type' => 'string', 'description' => 'uuid der Planung.'],
                'name' => ['type' => 'string'],
                'distribution_policy' => ['type' => 'string', 'description' => 'Verteilungsschlüssel (uuid|key), "" = Default.'],
            ],
            'required' => ['plan'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (! $teamId) {
                return ToolResult::error('Kein Team im Kontext.', 'TEAM_REQUIRED');
            }
            $plan = $this->findPlan((string) ($arguments['plan'] ?? ''), $teamId);
            if (! $plan) {
                return ToolResult::error('Planung nicht gefunden.', 'PLAN_NOT_FOUND');
            }

            if (array_key_exists('name', $arguments) && $arguments['name'] !== '') {
                $plan->name = (string) $arguments['name'];
            }

            if (array_key_exists('distribution_policy', $arguments)) {
                $ref = (string) $arguments['distribution_policy'];
                if ($ref === '') {
                    $plan->distribution_policy_id = null;
                } else {
                    $dp = ForecastDistributionPolicy::where(fn ($q) => $q->where('team_id', $teamId)->orWhereNull('team_id'))
                        ->where(fn ($q) => $q->where('uuid', $ref)->orWhere('key', $ref))
                        ->orderByRaw('team_id is null')->first();
                    if (! $dp) {
                        return ToolResult::error('Verteilungsschlüssel nicht gefunden.', 'POLICY_NOT_FOUND');
                    }
                    $plan->distribution_policy_id = $dp->id;
                }
            }

            $plan->save();

            return ToolResult::success([
                'uuid' => $plan->uuid,
                'name' => $plan->name,
                'distribution_policy_id' => $plan->distribution_policy_id,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('Fehler beim Aktualisieren: '.$e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return ['category' => 'action', 'tags' => ['forecast', 'plan', 'update'], 'read_only' => false, 'requires_team' => true, 'risk_level' => 'write'];
    }
}
