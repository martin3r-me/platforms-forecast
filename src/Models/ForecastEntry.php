<?php

namespace Platform\Forecast\Models;

use Illuminate\Database\Eloquent\Model;
use Platform\Forecast\Enums\TimeLevel;
use Platform\Forecast\Reconciliation\Mode;

/**
 * Eine "sparse" Zelle — aktueller Stand. mode = detail | plus.
 */
class ForecastEntry extends Model
{
    protected $table = 'forecast_entries';

    protected $fillable = [
        'team_id', 'plan_id', 'row_key', 'bucket_key', 'level', 'value', 'mode',
    ];

    protected $casts = [
        'value' => 'float',
        'mode' => Mode::class,
        'level' => TimeLevel::class,
    ];

    public function plan()
    {
        return $this->belongsTo(ForecastPlan::class, 'plan_id');
    }
}
