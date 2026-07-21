<?php

namespace Platform\Forecast\Tools;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Forecast\Models\ForecastDistributionPolicy;
use Platform\Forecast\Tools\Concerns\ResolvesContext;

class UpdateDistributionPolicyTool implements ToolContract, ToolMetadataContract
{
    use ResolvesContext;

    public function getName(): string
    {
        return 'forecast.distribution_policy.PUT';
    }

    public function getDescription(): string
    {
        return 'PUT /distribution-policies/{policy} – Bearbeitet einen team-eigenen Verteilungsschlüssel. '
            .'Parameter: policy (uuid oder key), name?, key? (even|seasonal), weights? (12), is_default?. '
            .'Globale (System-)Schlüssel sind nicht editierbar.';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'policy' => ['type' => 'string', 'description' => 'uuid oder key des Schlüssels.'],
                'name' => ['type' => 'string'],
                'key' => ['type' => 'string', 'enum' => ['even', 'seasonal']],
                'weights' => ['type' => 'array', 'items' => ['type' => 'number'], 'description' => '12 Monatsgewichte.'],
                'is_default' => ['type' => 'boolean'],
            ],
            'required' => ['policy'],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $teamId = $this->teamId($context);
            if (! $teamId) {
                return ToolResult::error('Kein Team im Kontext.', 'TEAM_REQUIRED');
            }

            $ref = (string) ($arguments['policy'] ?? '');
            $policy = ForecastDistributionPolicy::where('team_id', $teamId)
                ->where(fn ($q) => $q->where('uuid', $ref)->orWhere('key', $ref))->first();
            if (! $policy) {
                return ForecastDistributionPolicy::whereNull('team_id')->where(fn ($q) => $q->where('uuid', $ref)->orWhere('key', $ref))->exists()
                    ? ToolResult::error('Globaler Schlüssel ist nicht editierbar — leg einen team-eigenen an.', 'READONLY_GLOBAL')
                    : ToolResult::error('Schlüssel nicht gefunden.', 'NOT_FOUND');
            }

            if (array_key_exists('name', $arguments)) {
                $policy->name = (string) $arguments['name'];
            }
            if (array_key_exists('key', $arguments)) {
                $policy->key = $arguments['key'] === 'seasonal' ? 'seasonal' : 'even';
            }
            if (array_key_exists('weights', $arguments)) {
                $w = array_map('floatval', (array) $arguments['weights']);
                if ($w && count($w) !== 12) {
                    return ToolResult::error('weights braucht genau 12 Werte.', 'VALIDATION_ERROR');
                }
                $policy->weights = $w ?: null;
            }
            if (array_key_exists('is_default', $arguments) && $arguments['is_default']) {
                ForecastDistributionPolicy::where('team_id', $teamId)->where('id', '!=', $policy->id)->update(['is_default' => false]);
                $policy->is_default = true;
            } elseif (array_key_exists('is_default', $arguments)) {
                $policy->is_default = false;
            }
            $policy->save();

            return ToolResult::success([
                'uuid' => $policy->uuid, 'name' => $policy->name, 'key' => $policy->key,
                'weights' => $policy->weights, 'is_default' => $policy->is_default,
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('Fehler beim Bearbeiten: '.$e->getMessage(), 'EXECUTION_ERROR');
        }
    }

    public function getMetadata(): array
    {
        return ['category' => 'action', 'tags' => ['forecast', 'distribution_policy', 'update'], 'read_only' => false, 'requires_team' => true, 'risk_level' => 'write'];
    }
}
