<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Tools\Concerns\ResolvesContext;

class HistoryTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    public function getName(): string
    {
        return 'forecast.history.GET';
    }

    public function getDescription(): string
    {
        return 'GET /plans/{plan}/history – Das Eintrag-Ledger einer Planung: die erfassten Zellen mit '
            .'Wert, Modus (plus/set), Ebene und Zeitstempel (wann zuletzt gesetzt). Optional gefiltert auf eine '
            .'Zeile und/oder einen Bucket. Parameter: plan (uuid), row (row_key), bucket (Präfix, z. B. "2026-07"), '
            .'limit (Default 100).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan' => ['type' => 'string'],
                'row' => ['type' => 'string', 'description' => 'Nur diese Zeile (row_key).'],
                'bucket' => ['type' => 'string', 'description' => 'Bucket-Präfix-Filter (z. B. "2026" oder "2026-07").'],
                'limit' => ['type' => 'integer', 'description' => 'Max. Einträge (Default 100).'],
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

            $labels = [];
            foreach ($plan->resolvedRows() as $r) {
                $labels[$r->key] = $r->label;
            }

            $q = $plan->entries();
            if (! empty($arguments['row'])) {
                $q->where('row_key', (string) $arguments['row']);
            }
            if (! empty($arguments['bucket'])) {
                $q->where('bucket_key', 'like', ((string) $arguments['bucket']).'%');
            }
            $limit = max(1, min(1000, (int) ($arguments['limit'] ?? 100)));
            $entries = $q->orderByDesc('updated_at')->orderByDesc('id')->limit($limit)->get();

            return ToolResult::success([
                'plan' => ['uuid' => $plan->uuid, 'name' => $plan->name],
                'note' => 'Aktuelles Eintrag-Ledger (ein Eintrag je Zelle). Vollständige Vorwärts-Historie (Festschreiben/Settle) folgt.',
                'count' => $entries->count(),
                'entries' => $entries->map(fn ($e) => [
                    'row' => $e->row_key,
                    'label' => $labels[$e->row_key] ?? $e->row_key,
                    'bucket' => $e->bucket_key,
                    'level' => $e->level instanceof \Platform\Forecast\Enums\TimeLevel ? $e->level->value : $e->level,
                    'value' => $e->value,
                    'mode' => $e->mode instanceof \Platform\Forecast\Reconciliation\Mode ? $e->mode->value : $e->mode,
                    'set_at' => optional($e->updated_at)->toIso8601String(),
                    'created_at' => optional($e->created_at)->toIso8601String(),
                ])->all(),
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('Fehler: '.$e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return ['category' => 'query', 'tags' => ['forecast', 'history', 'ledger'], 'read_only' => true, 'requires_team' => true, 'risk_level' => 'read', 'idempotent' => true];
    }
}
