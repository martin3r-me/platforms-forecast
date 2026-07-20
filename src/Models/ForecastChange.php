<?php

namespace Platform\Forecast\Models;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Uid\UuidV7;

/**
 * Append-only Event-Log-Eintrag. Unveränderlich (nur created_at).
 * Jede Änderung an einer Planung erzeugt genau einen Eintrag + eine neue Version.
 */
class ForecastChange extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'forecast_changes';

    protected $fillable = [
        'uuid', 'team_id', 'plan_id', 'user_id', 'version', 'op',
        'row_key', 'bucket_key', 'level',
        'old_value', 'old_mode', 'new_value', 'new_mode', 'payload',
    ];

    protected $casts = [
        'version' => 'integer',
        'old_value' => 'float',
        'new_value' => 'float',
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) UuidV7::generate();
            }
            if (empty($model->created_at)) {
                $model->created_at = now();
            }
        });
    }

    public function plan()
    {
        return $this->belongsTo(ForecastPlan::class, 'plan_id');
    }
}
