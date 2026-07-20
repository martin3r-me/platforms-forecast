<?php

namespace Platform\Forecast\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Symfony\Component\Uid\UuidV7;

/**
 * Sperr-Regel (entkoppelt, benannt, wiederverwendbar). team_id null = global.
 */
class ForecastLockPolicy extends Model
{
    use SoftDeletes;

    protected $table = 'forecast_lock_policies';

    protected $fillable = [
        'uuid', 'team_id', 'name', 'period_level', 'lead_days', 'grace_days',
        'freeze_past', 'is_default', 'config',
    ];

    protected $casts = [
        'lead_days' => 'integer',
        'grace_days' => 'integer',
        'freeze_past' => 'boolean',
        'is_default' => 'boolean',
        'config' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) UuidV7::generate();
            }
        });
    }

    /** Wirksame Policy für ein Team: Team-Default, sonst globaler Default. */
    public static function resolveDefault(?int $teamId): ?self
    {
        return static::where('is_default', true)
            ->where(fn ($q) => $q->where('team_id', $teamId)->orWhereNull('team_id'))
            ->orderByRaw('team_id is null') // Team-spezifisch zuerst
            ->first();
    }

    /** @return array{period_level:string, lead_days:int, grace_days:int} */
    public function toRule(): array
    {
        return [
            'period_level' => $this->period_level,
            'lead_days' => $this->lead_days,
            'grace_days' => $this->grace_days,
        ];
    }
}
