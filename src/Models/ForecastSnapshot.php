<?php

namespace Platform\Forecast\Models;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Uid\UuidV7;

/**
 * Benannter Snapshot — materialisierter Voll-Stand einer Planung zu einer Version.
 */
class ForecastSnapshot extends Model
{
    protected $table = 'forecast_snapshots';

    protected $fillable = [
        'uuid', 'team_id', 'plan_id', 'user_id', 'name', 'version', 'payload', 'note',
    ];

    protected $casts = [
        'version' => 'integer',
        'payload' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) UuidV7::generate();
            }
        });
    }

    public function plan()
    {
        return $this->belongsTo(ForecastPlan::class, 'plan_id');
    }
}
