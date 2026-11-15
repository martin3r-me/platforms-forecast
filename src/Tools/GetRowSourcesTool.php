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

class GetRowSourcesTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    public function getName(): string
    {
        return 'forecast.row.sources.GET';
    }

    public function getDescription(): string
    {
        return 'GET /rows/sources – Die QUELL-ZEILEN (Verweise) einer Formel-/Verweis-Zeile: auf welche '
            .'Zeilen sie aggregiert, gewichtet, inkl. CROSS-PLAN-Verweisen auf Zeilen ANDERER Planungen '
            .'(Konsolidierung/Drill-down). Ziel über GENAU EINES: plan (uuid, effektive Zeile des Plans — '
            .'Instanz gewinnt vor Typ) ODER plan_type (uuid|key, Vorlagen-Zeile). Plus row_key. '
            .'source_plan_uuid = null bedeutet: Quelle liegt in derselben Planung. Verweis = Zeile→Zeile '
            .'über die ganze Zeitachse (bucket-gleich), NICHT Einzelzelle. Für die vertikale Org-Achse '
            .'(gleiche Zeile über den Org-Teilbaum) ist forecast.rollup.GET zuständig, nicht dieses Tool.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan' => ['type' => 'string', 'description' => 'Plan-uuid (effektive Zeile des Plans).'],
                'plan_type' => ['type' => 'string', 'description' => 'Typ uuid|key (Vorlagen-Zeile). Alternativ zu plan.'],
                'row_key' => ['type' => 'string', 'description' => 'key der Zeile.'],
            ],
            'required' => ['row_key'],
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
            $hasPlan = ! empty($arguments['plan']);
            $hasType = ! empty($arguments['plan_type']);
            if ($hasPlan === $hasType) {
                return ToolResult::error('Genau eines von plan ODER plan_type angeben.', 'VALIDATION_ERROR');
            }

            [$row, $scope, $meta] = $hasPlan
                ? $this->resolveFromPlan((string) $arguments['plan'], $rowKey, $teamId)
                : $this->resolveFromType((string) $arguments['plan_type'], $rowKey, $teamId);

            if ($row instanceof ToolResult) {
                return $row;
            }

            $sources = (new PlanService())->getRowSources($row);

            return ToolResult::success([
                'row_key' => $rowKey,
                'scope' => $scope,                 // 'instance' | 'type'
                'row' => $meta,                    // {kind, label}
                'has_cross_plan' => (bool) array_filter($sources, fn ($s) => $s['source_plan_id'] !== null),
                'sources' => $sources,
                'count' => count($sources),
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('Fehler beim Lesen der Quell-Zeilen: '.$e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    /** @return array{0: ForecastRow|ToolResult, 1: ?string, 2: ?array} */
    private function resolveFromPlan(string $planUuid, string $rowKey, int $teamId): array
    {
        $plan = $this->findPlan($planUuid, $teamId);
        if (! $plan) {
            return [ToolResult::error('Planung nicht gefunden.', 'PLAN_NOT_FOUND'), null, null];
        }
        $row = $plan->resolvedRows()->firstWhere('key', $rowKey);
        if (! $row) {
            return [ToolResult::error("Zeile '{$rowKey}' in der Planung nicht gefunden.", 'ROW_NOT_FOUND'), null, null];
        }
        $scope = ($row->plan_id !== null && (int) $row->plan_id === (int) $plan->id) ? 'instance' : 'type';

        return [$row, $scope, $this->meta($row)];
    }

    /** @return array{0: ForecastRow|ToolResult, 1: ?string, 2: ?array} */
    private function resolveFromType(string $typeRef, string $rowKey, int $teamId): array
    {
        $type = $this->findType($typeRef, $teamId);
        if (! $type) {
            return [ToolResult::error('Planungs-Typ nicht gefunden.', 'TYPE_NOT_FOUND'), null, null];
        }
        $row = $type->rows()->where('key', $rowKey)->first();
        if (! $row) {
            return [ToolResult::error("Zeile '{$rowKey}' im Typ nicht gefunden.", 'ROW_NOT_FOUND'), null, null];
        }

        return [$row, 'type', $this->meta($row)];
    }

    private function meta(ForecastRow $row): array
    {
        return [
            'kind' => $row->kind instanceof RowKind ? $row->kind->value : (string) $row->kind,
            'label' => (string) $row->label,
        ];
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'query',
            'tags' => ['forecast', 'row', 'sources', 'verweis', 'konsolidierung', 'drill'],
            'read_only' => true,
            'requires_team' => true,
            'risk_level' => 'read',
            'idempotent' => true,
        ];
    }
}
