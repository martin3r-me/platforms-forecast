<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Models\ForecastPlan;
use Platform\Forecast\Services\PlanService;
use Platform\Forecast\Tools\Concerns\ResolvesContext;

class DeletePlanTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    public function getName(): string
    {
        return 'forecast.plan.DELETE';
    }

    public function getDescription(): string
    {
        return 'DELETE /plans/{plan} – Löscht eine Planung (Soft-Delete, reversibel). '
            .'Parameter: plan (uuid). Hat die Planung Kind-Instanzen, ist cascade=true nötig, '
            .'um den ganzen Teilbaum zu löschen (sonst Fehler, um Verwaisung zu vermeiden).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan' => ['type' => 'string', 'description' => 'uuid der Planung.'],
                'cascade' => ['type' => 'boolean', 'description' => 'Auch alle Kind-Instanzen (Teilbaum) löschen. Default false.'],
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

            $cascade = (bool) ($arguments['cascade'] ?? false);
            $children = ForecastPlan::where('parent_plan_id', $plan->id)->get();

            if ($children->isNotEmpty() && ! $cascade) {
                return ToolResult::error(
                    'Planung hat '.$children->count().' Kind-Instanz(en) ('.$children->pluck('name')->implode(', ').'). '
                    .'Mit cascade=true den ganzen Teilbaum löschen, oder Kinder zuerst.',
                    'HAS_CHILDREN'
                );
            }

            // Teilbaum ab dieser Planung sammeln (nur cascade); von unten nach oben löschen
            $targets = [$plan];
            if ($cascade) {
                $queue = [$plan->id];
                while ($queue) {
                    $pid = array_shift($queue);
                    foreach (ForecastPlan::where('team_id', $teamId)->where('parent_plan_id', $pid)->get() as $child) {
                        $targets[] = $child;
                        $queue[] = $child->id;
                    }
                }
                $targets = array_reverse($targets); // Blätter zuerst
            }

            $svc = new PlanService();
            $deleted = [];
            foreach ($targets as $t) {
                $svc->deletePlan($t, $this->userId($context));
                $deleted[] = ['uuid' => $t->uuid, 'name' => $t->name];
            }

            return ToolResult::success([
                'deleted' => $deleted,
                'count' => count($deleted),
                'soft_delete' => true,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('Fehler beim Löschen der Planung: '.$e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['forecast', 'plan', 'delete'],
            'read_only' => false,
            'requires_team' => true,
            'risk_level' => 'write',
        ];
    }
}
