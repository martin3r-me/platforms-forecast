<?php

namespace Platform\Forecast\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Platform\Forecast\Enums\RowKind;
use Symfony\Component\Uid\UuidV7;

/**
 * Eine Zeile — gehört ENTWEDER zu einem Typ (Vorlage) ODER zu einer Instanz.
 */
class ForecastRow extends Model
{
    use SoftDeletes;

    protected $table = 'forecast_rows';

    protected $fillable = [
        'uuid', 'team_id', 'plan_type_id', 'plan_id',
        'key', 'label', 'kind', 'config', 'order',
    ];

    protected $casts = [
        'kind' => RowKind::class,
        'config' => 'array',
        'order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) UuidV7::generate();
            }
        });
    }

    public function planType()
    {
        return $this->belongsTo(ForecastPlanType::class, 'plan_type_id');
    }

    public function plan()
    {
        return $this->belongsTo(ForecastPlan::class, 'plan_id');
    }
}
