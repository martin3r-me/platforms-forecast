<?php

namespace Platform\Forecast\Services;

use Platform\Forecast\Models\ForecastPlan;
use Platform\Forecast\Reconciliation\Mode;
use Platform\Forecast\Reconciliation\Node;

/**
 * Org-Achse: rollt eine (row, bucket)-Zelle über den Organisations-Teilbaum ab
 * einem Knoten auf — DERSELBE Reconciliation-Motor wie bei der Zeit.
 *
 * Jeder Knoten trägt als "eigene Schätzung" den reconciled Wert seiner eigenen
 * Plan-Instanz; die Kind-Knoten laufen gemäß plan.org_mode (Detail|Plus) hinein.
 */
final class OrgRollup
{
    public function __construct(
        private readonly PlanReconciler $reconciler = new PlanReconciler(),
    ) {}

    /**
     * @return array{org_entity_id:int, row_key:string, bucket_key:string, value:float, rest:float, contributors:int}
     */
    public function rollup(int $planTypeId, int $orgEntityId, string $rowKey, string $bucketKey): array
    {
        $entityClass = \Platform\Organization\Models\OrganizationEntity::class;
        if (! class_exists($entityClass)) {
            throw new \RuntimeException('Organization-Modul nicht verfügbar.');
        }

        // Teilbaum: Wurzel + alle Nachfahren
        $ids = [$orgEntityId];
        $svcClass = \Platform\Organization\Services\EntityHierarchyService::class;
        if (class_exists($svcClass)) {
            $map = (new $svcClass())->getAllDescendantMap([$orgEntityId]);
            $ids = array_values(array_unique(array_merge($ids, $map[$orgEntityId] ?? [])));
        }

        /** @var \Illuminate\Support\Collection $entities */
        $entities = $entityClass::whereIn('id', $ids)->get(['id', 'parent_entity_id']);

        $plans = ForecastPlan::where('plan_type_id', $planTypeId)
            ->whereIn('organization_entity_id', $ids)
            ->get()->keyBy('organization_entity_id');

        // Ein Node je Entity; eigene Schätzung = reconciled Wert der eigenen Instanz
        $nodes = [];
        $contributors = 0;
        foreach ($entities as $ent) {
            $node = new Node((string) $ent->id);
            $plan = $plans->get($ent->id);
            if ($plan) {
                $node->estimate = $this->reconciler->cell($plan, $rowKey, $bucketKey)['value'];
                $contributors++;
            }
            $nodes[$ent->id] = ['node' => $node, 'parent' => $ent->parent_entity_id, 'plan' => $plan];
        }

        // Kinder in Eltern hängen (nur innerhalb des Teilbaums)
        foreach ($nodes as $n) {
            $parentId = $n['parent'];
            if ($parentId !== null && isset($nodes[$parentId])) {
                $mode = $n['plan'] && $n['plan']->org_mode instanceof Mode ? $n['plan']->org_mode : Mode::Detail;
                $nodes[$parentId]['node']->addChild($n['node'], $mode);
            }
        }

        $root = $nodes[$orgEntityId]['node'] ?? new Node((string) $orgEntityId);

        return [
            'org_entity_id' => $orgEntityId,
            'row_key' => $rowKey,
            'bucket_key' => $bucketKey,
            'value' => round($root->value(), 4),
            'rest' => round($root->rest(), 4),
            'contributors' => $contributors,
        ];
    }
}
