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
            .'period_start? / period_end? (YYYY-MM-DD — Planungszeitraum; z. B. period_end=2027-12-31, '
            .'um den Horizont auf ein weiteres Jahr zu verlängern), base_level? (year|month|day|hour), '
            .'distribution_policy? (uuid oder key; leerer String = auf Default zurücksetzen), '
            .'parent_plan? (uuid der Konsolidierungs-Elternplanung; leerer String = lösen), '
            .'organization_entity_id? (Org-Knoten; null = lösen).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan' => ['type' => 'string', 'description' => 'uuid der Planung.'],
                'name' => ['type' => 'string'],
                'period_start' => ['type' => 'string', 'description' => 'YYYY-MM-DD — Beginn des Planungszeitraums.'],
                'period_end' => ['type' => 'string', 'description' => 'YYYY-MM-DD — Ende des Planungszeitraums (z. B. 2027-12-31, um ein weiteres Jahr aufzunehmen).'],
                'base_level' => ['type' => 'string', 'enum' => ['year', 'month', 'day', 'hour'], 'description' => 'Standard-Zeitebene der Erfassung.'],
                'distribution_policy' => ['type' => 'string', 'description' => 'Verteilungsschlüssel (uuid|key), "" = Default.'],
                'parent_plan' => ['type' => 'string', 'description' => 'uuid der Konsolidierungs-Elternplanung (dieser Plan wird Kind davon), "" = lösen.'],
                'organization_entity_id' => ['type' => ['integer', 'null'], 'description' => 'Org-Knoten (organization_entities.id), null = lösen.'],
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

            if (array_key_exists('parent_plan', $arguments)) {
                $ref = (string) $arguments['parent_plan'];
                if ($ref === '') {
                    $plan->parent_plan_id = null;
                } else {
                    $parent = $this->findPlan($ref, $teamId);
                    if (! $parent) {
                        return ToolResult::error('Elternplanung nicht gefunden.', 'PARENT_PLAN_NOT_FOUND');
                    }
                    for ($p = $parent; $p; $p = $p->parentPlan) {
                        if ($p->id === $plan->id) {
                            return ToolResult::error('Zyklus: Planung kann nicht ihr eigener Vorfahr sein.', 'PARENT_PLAN_CYCLE');
                        }
                    }
                    $plan->parent_plan_id = $parent->id;
                }
            }

            if (array_key_exists('organization_entity_id', $arguments)) {
                $plan->organization_entity_id = $arguments['organization_entity_id'] === null || $arguments['organization_entity_id'] === ''
                    ? null
                    : (int) $arguments['organization_entity_id'];
            }

            // Planungszeitraum / Erfassungsebene aktualisieren — z. B. Horizont auf ein weiteres
            // Jahr verlängern (period_end=2027-12-31). Nur bei gültiger Angabe; Datumsformat YYYY-MM-DD.
            foreach (['period_start', 'period_end'] as $pk) {
                if (array_key_exists($pk, $arguments) && $arguments[$pk] !== null && $arguments[$pk] !== '') {
                    $d = \DateTime::createFromFormat('Y-m-d', (string) $arguments[$pk]);
                    if (! $d || $d->format('Y-m-d') !== (string) $arguments[$pk]) {
                        return ToolResult::error("{$pk} muss im Format YYYY-MM-DD sein.", 'VALIDATION_ERROR');
                    }
                    $plan->{$pk} = (string) $arguments[$pk];
                }
            }
            if (array_key_exists('base_level', $arguments) && $arguments['base_level'] !== '') {
                $bl = (string) $arguments['base_level'];
                if (! in_array($bl, ['year', 'month', 'day', 'hour'], true)) {
                    return ToolResult::error('base_level muss year|month|day|hour sein.', 'VALIDATION_ERROR');
                }
                $plan->base_level = $bl;
            }

            if ($plan->period_start && $plan->period_end && $plan->period_end->lt($plan->period_start)) {
                return ToolResult::error('period_end darf nicht vor period_start liegen.', 'VALIDATION_ERROR');
            }

            $plan->save();

            return ToolResult::success([
                'uuid' => $plan->uuid,
                'name' => $plan->name,
                'base_level' => $plan->base_level,
                'period_start' => $plan->period_start?->toDateString(),
                'period_end' => $plan->period_end?->toDateString(),
                'distribution_policy_id' => $plan->distribution_policy_id,
                'parent_plan_id' => $plan->parent_plan_id,
                'organization_entity_id' => $plan->organization_entity_id,
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
