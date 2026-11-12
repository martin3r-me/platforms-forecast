<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Services\PlanReconciler;
use Platform\Forecast\Services\PlanService;
use Platform\Forecast\Tools\Concerns\ResolvesContext;

class GetBreakdownTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    public function getName(): string
    {
        return 'forecast.cell.breakdown.GET';
    }

    public function getDescription(): string
    {
        return 'GET /cells/breakdown – Die AUFSCHLÜSSELUNG einer Zelle: die benannten Unterzeilen '
            .'(Label + Betrag), deren Summe den Zellwert ergibt. Parameter: plan (uuid), row_key, '
            .'bucket_key, optional channel (plan|actual, Default plan). Leere Liste = Zelle ist nicht '
            .'aufgeschlüsselt. NICHT zu verwechseln mit forecast.cell.PUT mode=detail: "detail" dort '
            .'ist die Spreading-Mechanik auf dem Zellwert selbst, NICHT diese benannten Unterzeilen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan' => ['type' => 'string', 'description' => 'Plan-uuid.'],
                'row_key' => ['type' => 'string'],
                'bucket_key' => ['type' => 'string', 'description' => 'Jahr "2026", Monat "2026-07", Tag "2026-07-12".'],
                'channel' => ['type' => 'string', 'enum' => ['plan', 'actual'], 'description' => 'plan = Soll (Default), actual = Ist.'],
            ],
            'required' => ['plan', 'row_key', 'bucket_key'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (! $teamId) {
                return ToolResult::error('Kein Team im Kontext.', 'TEAM_REQUIRED');
            }
            foreach (['plan', 'row_key', 'bucket_key'] as $req) {
                if (empty($arguments[$req])) {
                    return ToolResult::error("Parameter '{$req}' ist erforderlich.", 'VALIDATION_ERROR');
                }
            }
            $channel = (string) ($arguments['channel'] ?? 'plan');
            if (! in_array($channel, ['plan', 'actual'], true)) {
                return ToolResult::error('channel muss plan oder actual sein.', 'VALIDATION_ERROR');
            }
            $plan = $this->findPlan((string) $arguments['plan'], $teamId);
            if (! $plan) {
                return ToolResult::error('Planung nicht gefunden.', 'PLAN_NOT_FOUND');
            }

            $rowKey = (string) $arguments['row_key'];
            $bucketKey = (string) $arguments['bucket_key'];

            $service = new PlanService();
            $items = $service->getBreakdown($plan, $rowKey, $bucketKey, $channel);
            $cell = (new PlanReconciler())->cell($plan, $rowKey, $bucketKey, $channel);
            $sum = round(array_sum(array_map(fn ($i) => (float) $i['value'], $items)), 6);

            return ToolResult::success([
                'plan' => $plan->uuid,
                'row_key' => $rowKey,
                'bucket_key' => $bucketKey,
                'channel' => $channel,
                'is_broken_down' => $items !== [],
                'items' => $items,            // [{id, label, value, ordinal}, …]
                'sum' => $sum,                // Σ der Unterzeilen
                'count' => count($items),
                'reconciled' => $cell,        // fertiger Zellwert (= sum, solange aufgeschlüsselt)
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('Fehler beim Lesen der Aufschlüsselung: '.$e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['forecast', 'cell', 'breakdown', 'aufschlüsselung', 'unterzeilen'],
            'read_only' => true,
            'requires_team' => true,
            'risk_level' => 'read',
            'idempotent' => true,
        ];
    }
}
