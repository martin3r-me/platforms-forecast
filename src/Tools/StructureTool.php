<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Models\ForecastPlan;
use Platform\Forecast\Tools\Concerns\ResolvesContext;

class StructureTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    public function getName(): string
    {
        return 'forecast.structure.GET';
    }

    public function getDescription(): string
    {
        return 'GET /structure – Die Ordner/Blatt-Hierarchie der Planungen (Konsolidierungs-Baum über parent_plan). '
            .'Je Knoten: Rolle (ordner = bündelt Kinder / blatt = erfasst Zahlen), Typ, Version, Kinder und '
            .'Drill-Quellen (Pläne, aus denen Zeilen gespeist werden). Parameter: plan (uuid, optional = Teilbaum '
            .'ab hier + Ahnen-Pfad; sonst alle Wurzeln des Teams).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan' => ['type' => 'string', 'description' => 'Optional: Wurzel des Teilbaums.'],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (! $teamId) {
                return ToolResult::error('Kein Team im Kontext.', 'TEAM_REQUIRED');
            }

            $all = ForecastPlan::where('team_id', $teamId)->get();
            $byParent = [];
            $byId = [];
            foreach ($all as $p) {
                $byId[$p->id] = $p;
                $byParent[$p->parent_plan_id ?? 0][] = $p;
            }

            if (! empty($arguments['plan'])) {
                $root = $this->findPlan((string) $arguments['plan'], $teamId);
                if (! $root) {
                    return ToolResult::error('Planung nicht gefunden.', 'PLAN_NOT_FOUND');
                }
                $ancestors = [];
                $cur = $root->parent_plan_id;
                while ($cur && isset($byId[$cur])) {
                    $ancestors[] = ['uuid' => $byId[$cur]->uuid, 'name' => $byId[$cur]->name];
                    $cur = $byId[$cur]->parent_plan_id;
                }

                return ToolResult::success([
                    'ancestors' => array_reverse($ancestors),
                    'tree' => $this->node($root, $byParent, $byId),
                ]);
            }

            $roots = $byParent[0] ?? [];

            return ToolResult::success([
                'roots' => array_map(fn ($r) => $this->node($r, $byParent, $byId), $roots),
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('Fehler: '.$e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    private function node(ForecastPlan $plan, array $byParent, array $byId): array
    {
        $children = $byParent[$plan->id] ?? [];

        // Drill-Quellen: Pläne, aus denen Formelzeilen gespeist werden (source_plan_id).
        $drill = [];
        foreach ($plan->resolvedRows() as $r) {
            if ($r->kind->value !== 'formula') {
                continue;
            }
            foreach ($r->sources as $s) {
                if ($s->source_plan_id !== null && isset($byId[$s->source_plan_id])) {
                    $drill[$s->source_plan_id] = ['uuid' => $byId[$s->source_plan_id]->uuid, 'name' => $byId[$s->source_plan_id]->name];
                }
            }
        }

        return [
            'uuid' => $plan->uuid,
            'name' => $plan->name,
            'role' => count($children) ? 'ordner' : 'blatt',
            'plan_type' => $plan->planType?->name,
            'version' => $plan->current_version,
            'organization_entity_id' => $plan->organization_entity_id,
            'drill_sources' => array_values($drill),
            'children' => array_map(fn ($c) => $this->node($c, $byParent, $byId), $children),
        ];
    }

    public function getMetadata(): array
    {
        return ['category' => 'query', 'tags' => ['forecast', 'structure', 'tree'], 'read_only' => true, 'requires_team' => true, 'risk_level' => 'read', 'idempotent' => true];
    }
}
