<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Enums\RowKind;
use Platform\Forecast\Models\ForecastRow;
use Platform\Forecast\Services\PlanService;
use Platform\Forecast\Tools\Concerns\ResolvesContext;

class SetRowSourcesTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    public function getName(): string
    {
        return 'forecast.row.sources.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /rows/sources – Setzt die QUELL-ZEILEN (Verweise) einer Formel-/Verweis-Zeile: '
            .'ersetzt die Liste VOLLSTÄNDIG. Jede Quelle: {row_key, weight?} für die SELBE Planung, '
            .'oder {plan_uuid, row_key, weight?} für einen CROSS-PLAN-Verweis auf eine Zeile einer '
            .'ANDEREN Planung (Konsolidierung/Drill-down; Zeile→Zeile, bucket-gleich). Leere Liste = '
            .'alle Verweise entfernen. Ziel über GENAU EINES: plan (uuid) ODER plan_type (uuid|key). '
            .'Bei plan wird pro Instanz gearbeitet: existiert nur die geteilte Typ-Zeile, wird ein '
            .'Instanz-Override angelegt (andere Instanzen bleiben unberührt). Bei plan_type ändert sich '
            .'die Vorlage für ALLE Instanzen ohne eigenen Override. Nur Formel-/Verweis-/Summen-/Prozent-'
            .'Zeilen (input-Zeilen ignorieren Quellen). NICHT zu verwechseln mit forecast.rollup.GET '
            .'(vertikale Org-Achse) oder der Zell-Aufschlüsselung (forecast.cell.breakdown.PUT).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan' => ['type' => 'string', 'description' => 'Plan-uuid (Instanz-Override).'],
                'plan_type' => ['type' => 'string', 'description' => 'Typ uuid|key (Vorlagen-Zeile, gilt für alle Instanzen). Alternativ zu plan.'],
                'row_key' => ['type' => 'string', 'description' => 'key der Ziel-Zeile.'],
                'sources' => [
                    'type' => 'array',
                    'description' => 'Die Quell-Zeilen. Leer = alle Verweise entfernen.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'row_key' => ['type' => 'string', 'description' => 'key der Quell-Zeile.'],
                            'plan_uuid' => ['type' => 'string', 'description' => 'uuid der Quell-Planung. Weglassen = selbe Planung.'],
                            'weight' => ['type' => 'number', 'description' => 'Gewicht (Default 1). Negativ = subtrahierend.'],
                        ],
                        'required' => ['row_key'],
                    ],
                ],
            ],
            'required' => ['row_key', 'sources'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (! $teamId) {
                return ToolResult::error('Kein Team im Kontext.', 'TEAM_REQUIRED');
            }
            $rowKey = (string) ($arguments['row_key'] ?? '');
            if ($rowKey === '') {
                return ToolResult::error("Parameter 'row_key' ist erforderlich.", 'VALIDATION_ERROR');
            }
            if (! array_key_exists('sources', $arguments) || ! is_array($arguments['sources'])) {
                return ToolResult::error('sources muss eine Liste sein (leer = Verweise entfernen).', 'VALIDATION_ERROR');
            }
            $hasPlan = ! empty($arguments['plan']);
            $hasType = ! empty($arguments['plan_type']);
            if ($hasPlan === $hasType) {
                return ToolResult::error('Genau eines von plan ODER plan_type angeben.', 'VALIDATION_ERROR');
            }

            // Quellen vorprüfen + Cross-Plan-uuids team-sicher zu plan_id auflösen.
            $items = [];
            foreach (array_values($arguments['sources']) as $i => $src) {
                if (! is_array($src)) {
                    return ToolResult::error("sources[{$i}] muss ein Objekt {row_key, plan_uuid?, weight?} sein.", 'VALIDATION_ERROR');
                }
                $srcRowKey = trim((string) ($src['row_key'] ?? $src['key'] ?? ''));
                if ($srcRowKey === '') {
                    return ToolResult::error("sources[{$i}].row_key fehlt.", 'VALIDATION_ERROR');
                }
                if (array_key_exists('weight', $src) && ! is_numeric($src['weight'])) {
                    return ToolResult::error("sources[{$i}].weight muss eine Zahl sein.", 'VALIDATION_ERROR');
                }
                $item = ['row_key' => $srcRowKey, 'weight' => $src['weight'] ?? 1];
                if (! empty($src['plan_uuid'])) {
                    $srcPlan = $this->findPlan((string) $src['plan_uuid'], $teamId);
                    if (! $srcPlan) {
                        return ToolResult::error("sources[{$i}]: Quell-Planung '{$src['plan_uuid']}' nicht gefunden (Team).", 'SOURCE_PLAN_NOT_FOUND');
                    }
                    $item['plan_id'] = $srcPlan->id;
                }
                $items[] = $item;
            }

            // Ziel-Zeile auflösen.
            $service = new PlanService();
            if ($hasType) {
                $type = $this->findType((string) $arguments['plan_type'], $teamId);
                if (! $type) {
                    return ToolResult::error('Planungs-Typ nicht gefunden.', 'TYPE_NOT_FOUND');
                }
                $row = $type->rows()->where('key', $rowKey)->first();
                if (! $row) {
                    return ToolResult::error("Zeile '{$rowKey}' im Typ nicht gefunden.", 'ROW_NOT_FOUND');
                }
                $scope = 'type';
            } else {
                $plan = $this->findPlan((string) $arguments['plan'], $teamId);
                if (! $plan) {
                    return ToolResult::error('Planung nicht gefunden.', 'PLAN_NOT_FOUND');
                }
                $row = $service->materializeInstanceRow($plan, $rowKey);
                if (! $row) {
                    return ToolResult::error("Zeile '{$rowKey}' weder als Instanz- noch als Typ-Zeile vorhanden.", 'ROW_NOT_FOUND');
                }
                $scope = 'instance';
            }

            if ($row->kind === RowKind::Input) {
                return ToolResult::error('input-Zeilen haben keine Quellen — Verweise gibt es nur auf Formel-/Verweis-Zeilen.', 'ROW_KIND_INVALID');
            }

            $sources = $service->replaceRowSources($row, $items);

            return ToolResult::success([
                'row_key' => $rowKey,
                'scope' => $scope,                 // 'instance' | 'type'
                'row' => [
                    'kind' => $row->kind instanceof RowKind ? $row->kind->value : (string) $row->kind,
                    'label' => (string) $row->label,
                ],
                'has_cross_plan' => (bool) array_filter($sources, fn ($s) => $s['source_plan_id'] !== null),
                'sources' => $sources,
                'count' => count($sources),
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('Fehler beim Setzen der Quell-Zeilen: '.$e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['forecast', 'row', 'sources', 'verweis', 'konsolidierung', 'drill'],
            'read_only' => false,
            'requires_team' => true,
            'risk_level' => 'write',
        ];
    }
}
