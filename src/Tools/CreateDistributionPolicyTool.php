<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Models\ForecastDistributionPolicy;
use Platform\Forecast\Tools\Concerns\ResolvesContext;

class CreateDistributionPolicyTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    public function getName(): string
    {
        return 'forecast.distribution_policy.POST';
    }

    public function getDescription(): string
    {
        return 'POST /distribution-policies – Legt einen Verteilungsschlüssel an (wie ein gröberer Wert / '
            .'Rest nach unten fällt). Parameter: name (required), key (even|seasonal, default even), '
            .'weights (12 Monatsgewichte, relativ – nur bei seasonal), is_default (bool). team-eigen.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'key' => ['type' => 'string', 'enum' => ['even', 'seasonal'], 'description' => 'even = gleichmäßig; seasonal = nach Monatsgewichten.'],
                'weights' => ['type' => 'array', 'items' => ['type' => 'number'], 'description' => '12 relative Monatsgewichte (Jan..Dez), nur bei seasonal.'],
                'is_default' => ['type' => 'boolean', 'description' => 'Als Team-Standard setzen (hebt anderen Team-Standard auf).'],
            ],
            'required' => ['name'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (! $teamId) {
                return ToolResult::error('Kein Team im Kontext.', 'TEAM_REQUIRED');
            }
            if (empty($arguments['name'])) {
                return ToolResult::error('name ist erforderlich.', 'VALIDATION_ERROR');
            }

            $key = ($arguments['key'] ?? 'even') === 'seasonal' ? 'seasonal' : 'even';
            $weights = null;
            if ($key === 'seasonal') {
                $weights = array_map('floatval', (array) ($arguments['weights'] ?? []));
                if (count($weights) !== 12) {
                    return ToolResult::error('seasonal braucht genau 12 Monatsgewichte.', 'VALIDATION_ERROR');
                }
            }

            $isDefault = (bool) ($arguments['is_default'] ?? false);
            if ($isDefault) {
                ForecastDistributionPolicy::where('team_id', $teamId)->where('is_default', true)->update(['is_default' => false]);
            }

            $policy = ForecastDistributionPolicy::create([
                'team_id' => $teamId,
                'name' => (string) $arguments['name'],
                'key' => $key,
                'weights' => $weights,
                'is_default' => $isDefault,
            ]);

            return ToolResult::success([
                'uuid' => $policy->uuid,
                'name' => $policy->name,
                'key' => $policy->key,
                'weights' => $policy->weights,
                'is_default' => $policy->is_default,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('Fehler beim Anlegen: '.$e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'action',
            'tags' => ['forecast', 'distribution_policy', 'create'],
            'read_only' => false,
            'requires_team' => true,
            'risk_level' => 'write',
        ];
    }
}
