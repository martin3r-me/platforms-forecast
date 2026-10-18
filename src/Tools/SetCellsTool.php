<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Services\PlanService;
use Platform\Forecast\Tools\Concerns\ResolvesContext;

class SetCellsTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    public function getName(): string
    {
        return 'forecast.cells.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /cells:batch – Setzt VIELE Zellen EINER Planung in einer einzigen, atomaren '
            .'Transaktion (unter Plan-Sperre). Dafür gedacht, Importe/Paste zu schreiben, statt '
            .'viele parallele forecast.cell.PUT abzusetzen (die den Versionszähler racen und Writes '
            .'verlieren können). Parameter: plan (uuid), optional channel (plan|actual, Default plan), '
            .'optional mode (detail|plus, Default detail — gilt je Zelle, wenn dort kein mode steht), '
            .'cells (Array von {row_key, bucket_key, value, mode?}). Jede Zelle erzeugt eine Version. '
            .'Ungültige/gesperrte Zellen werden übersprungen und in "errors" gemeldet; der Rest wird geschrieben.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan' => ['type' => 'string', 'description' => 'Plan-uuid.'],
                'channel' => ['type' => 'string', 'enum' => ['plan', 'actual'], 'description' => 'Wert-Kanal: plan = Soll (Default), actual = Ist.'],
                'mode' => ['type' => 'string', 'enum' => ['detail', 'plus'], 'description' => 'Default-Modus je Zelle ohne eigenes mode. detail = verfeinert, plus = addiert.'],
                'cells' => [
                    'type' => 'array',
                    'description' => 'Zu setzende Zellen.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'row_key' => ['type' => 'string'],
                            'bucket_key' => ['type' => 'string', 'description' => 'Jahr "2026", Monat "2026-07", Tag "2026-07-12".'],
                            'value' => ['type' => 'number'],
                            'mode' => ['type' => 'string', 'enum' => ['detail', 'plus']],
                        ],
                        'required' => ['row_key', 'bucket_key', 'value'],
                    ],
                ],
            ],
            'required' => ['plan', 'cells'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (! $teamId) {
                return ToolResult::error('Kein Team im Kontext.', 'TEAM_REQUIRED');
            }
            if (empty($arguments['plan'])) {
                return ToolResult::error("Parameter 'plan' ist erforderlich.", 'VALIDATION_ERROR');
            }
            if (empty($arguments['cells']) || ! is_array($arguments['cells'])) {
                return ToolResult::error("Parameter 'cells' (Array) ist erforderlich.", 'VALIDATION_ERROR');
            }

            $channel = (string) ($arguments['channel'] ?? 'plan');
            if (! in_array($channel, ['plan', 'actual'], true)) {
                return ToolResult::error('channel muss plan oder actual sein.', 'VALIDATION_ERROR');
            }

            $defaultMode = (string) ($arguments['mode'] ?? 'detail');
            if (! in_array($defaultMode, ['detail', 'plus'], true)) {
                return ToolResult::error('mode muss detail oder plus sein.', 'VALIDATION_ERROR');
            }

            $plan = $this->findPlan((string) $arguments['plan'], $teamId);
            if (! $plan) {
                return ToolResult::error('Planung nicht gefunden.', 'PLAN_NOT_FOUND');
            }

            // Default-Modus je Zelle einsetzen, wenn dort keiner angegeben ist.
            $cells = array_map(function ($c) use ($defaultMode) {
                if (is_array($c) && empty($c['mode'])) {
                    $c['mode'] = $defaultMode;
                }

                return $c;
            }, $arguments['cells']);

            $result = (new PlanService())->setCells($plan, $cells, $this->userId($context), false, $channel);

            $plan->refresh();

            return ToolResult::success([
                'plan' => $plan->uuid,
                'channel' => $channel,
                'written' => $result['written'],
                'skipped' => $result['skipped'],
                'version' => $plan->current_version,
                'errors' => $result['errors'],
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('Fehler beim Setzen der Zellen: '.$e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['forecast', 'cell', 'set', 'batch', 'import'],
            'read_only' => false,
            'requires_team' => true,
            'risk_level' => 'write',
        ];
    }
}
