<?php

namespace Platform\Forecast\Models;

use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Uid\UuidV7;

/**
 * Eine Quelle einer Formel-/Verweis-Zeile. source_plan_id null = selbe Planung;
 * gesetzt = Verweis auf eine Zeile einer ANDEREN Planung (Konsolidierung/Drill-down).
 */
class ForecastRowSource extends Model
{
    protected $table = 'forecast_row_sources';

    protected $fillable = [
        'uuid', 'row_id', 'source_plan_id', 'source_row_key', 'weight', 'sort_order',
    ];

    protected $casts = [
        'weight' => 'float',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) UuidV7::generate();
            }
        });
    }

    public function row()
    {
        return $this->belongsTo(ForecastRow::class, 'row_id');
    }

    public function sourcePlan()
    {
        return $this->belongsTo(ForecastPlan::class, 'source_plan_id');
    }
}
