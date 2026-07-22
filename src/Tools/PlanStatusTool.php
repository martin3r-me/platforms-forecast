<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Services\PlanAnalyzer;
use Platform\Forecast\Tools\Concerns\ResolvesContext;

class PlanStatusTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    public function getName(): string
    {
        return 'forecast.status.GET';
    }

    public function getDescription(): string
    {
        return 'GET /plans/{plan}/status – Sperr-Status je Periode: wo darf gerade geschrieben werden? Liefert '
            .'die Spalten mit state (open|pending|closed|mixed) + Tage bis Öffnen/Schließen, plus die wirksame '
            .'Sperr-Regel. Parameter: plan (uuid), bucket (Container, "" = Jahre) oder level (year|quarter|month|day).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'plan' => ['type' => 'string'],
                'bucket' => ['type' => 'string'],
                'level' => ['type' => 'string', 'enum' => ['year', 'quarter', 'month', 'day']],
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

            $a = (new PlanAnalyzer())->analyze(
                $plan,
                (string) ($arguments['bucket'] ?? ''),
                ! empty($arguments['level']) ? (string) $arguments['level'] : null,
            );

            $counts = ['open' => 0, 'pending' => 0, 'closed' => 0, 'mixed' => 0];
            foreach ($a['columns'] as $c) {
                $counts[$c['state']] = ($counts[$c['state']] ?? 0) + 1;
            }

            return ToolResult::success([
                'plan' => ['uuid' => $a['plan']['uuid'], 'name' => $a['plan']['name']],
                'lock' => $a['lock'],
                'level' => $a['level'],
                'summary' => $counts,
                'columns' => $a['columns'],
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('Fehler: '.$e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return ['category' => 'query', 'tags' => ['forecast', 'status', 'lock'], 'read_only' => true, 'requires_team' => true, 'risk_level' => 'read', 'idempotent' => true];
    }
}
