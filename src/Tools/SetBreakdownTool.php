<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Services\PlanReconciler;
use Platform\Forecast\Services\PlanService;
use Platform\Forecast\Tools\Concerns\ResolvesContext;

class SetBreakdownTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    public function getName(): string
    {
        return 'forecast.cell.breakdown.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /cells/breakdown – Schlüsselt eine Zelle in benannte Unterzeilen auf: ersetzt die '
            .'bestehende Aufschlüsselung VOLLSTÄNDIG durch die übergebene Liste. Der Zellwert wird '
            .'synchron auf Σ der Unterzeilen gesetzt; die Zelle ist danach nur noch über dieses Tool '
            .'änderbar (direktes forecast.cell.PUT ist dann gesperrt). Eine LEERE items-Liste hebt die '
            .'Aufschlüsselung auf — der zuletzt errechnete Summenwert bleibt als frei tippbarer '
            .'Direktwert stehen. Parameter: plan (uuid), row_key, bucket_key, items (Liste {label, value}), '
            .'optional channel (plan|actual, Default plan). NICHT zu verwechseln mit forecast.cell.PUT '
            .'mode=detail: das ist die Spreading-Mechanik auf dem Zellwert, NICHT diese benannten '
            .'Unterzeilen. value ist vorzeichenerhaltend (eine Entlastung in einer Kostenzeile ist negativ).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan' => ['type' => 'string', 'description' => 'Plan-uuid.'],
                'row_key' => ['type' => 'string'],
                'bucket_key' => ['type' => 'string', 'description' => 'Jahr "2026", Monat "2026-07", Tag "2026-07-12".'],
                'items' => [
                    'type' => 'array',
                    'description' => 'Die Unterzeilen. Leer = Aufschlüsselung aufheben. Zeilen ohne Label werden verworfen.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'label' => ['type' => 'string', 'description' => 'Name der Unterzeile.'],
                            'value' => ['type' => 'number', 'description' => 'Betrag (vorzeichenerhaltend).'],
                        ],
                        'required' => ['label', 'value'],
                    ],
                ],
                'channel' => ['type' => 'string', 'enum' => ['plan', 'actual'], 'description' => 'plan = Soll (Default), actual = Ist.'],
            ],
            'required' => ['plan', 'row_key', 'bucket_key', 'items'],
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
            if (! array_key_exists('items', $arguments) || ! is_array($arguments['items'])) {
                return ToolResult::error('items muss eine Liste sein (leer = Aufschlüsselung aufheben).', 'VALIDATION_ERROR');
            }
            $channel = (string) ($arguments['channel'] ?? 'plan');
            if (! in_array($channel, ['plan', 'actual'], true)) {
                return ToolResult::error('channel muss plan oder actual sein.', 'VALIDATION_ERROR');
            }

            // Items vorprüfen: jede Zeile braucht ein Label und eine numerische value.
            $items = [];
            foreach (array_values($arguments['items']) as $i => $it) {
                if (! is_array($it)) {
                    return ToolResult::error("items[{$i}] muss ein Objekt {label, value} sein.", 'VALIDATION_ERROR');
                }
                if (array_key_exists('value', $it) && ! is_numeric($it['value'])) {
                    return ToolResult::error("items[{$i}].value muss eine Zahl sein.", 'VALIDATION_ERROR');
                }
                $items[] = [
                    'label' => (string) ($it['label'] ?? ''),
                    'value' => (float) ($it['value'] ?? 0),
                ];
            }

            $plan = $this->findPlan((string) $arguments['plan'], $teamId);
            if (! $plan) {
                return ToolResult::error('Planung nicht gefunden.', 'PLAN_NOT_FOUND');
            }

            $rowKey = (string) $arguments['row_key'];
            $bucketKey = (string) $arguments['bucket_key'];

            $service = new PlanService();
            $result = $service->setBreakdown($plan, $rowKey, $bucketKey, $items, $this->userId($context), false, $channel);

            $plan->refresh();
            $cell = (new PlanReconciler())->cell($plan, $rowKey, $bucketKey, $channel);

            return ToolResult::success([
                'plan' => $plan->uuid,
                'row_key' => $rowKey,
                'bucket_key' => $bucketKey,
                'channel' => $channel,
                'is_broken_down' => $result['count'] > 0,
                'items' => $result['items'],   // [{label, value, ordinal}, …]
                'sum' => $result['sum'],
                'count' => $result['count'],
                'reconciled' => $cell,         // fertiger Zellwert nach dem Schreiben
                'version' => $plan->current_version,
            ]);
        } catch (\DomainException $e) {
            return ToolResult::error($e->getMessage(), 'CELL_NOT_EDITABLE');
        } catch (\Throwable $e) {
            return ToolResult::error('Fehler beim Aufschlüsseln der Zelle: '.$e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['forecast', 'cell', 'breakdown', 'aufschlüsselung', 'unterzeilen'],
            'read_only' => false,
            'requires_team' => true,
            'risk_level' => 'write',
        ];
    }
}
