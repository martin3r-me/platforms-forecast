<?php

namespace Platform\Forecast\Models;

use Illuminate\Database\Eloquent\Model;
use Platform\Forecast\Enums\TimeLevel;

/**
 * Eine benannte Unterzeile einer aufgeschlüsselten Zelle (Label + Betrag).
 *
 * Mehrere Unterzeilen je (plan, row, bucket, channel) ergeben in Summe den Wert der
 * Oberzelle in forecast_entries. value ist vorzeichenerhaltend — eine Entlastung in
 * einer Kostenzeile ist negativ.
 */
class ForecastEntryDetail extends Model
{
    protected $table = 'forecast_entry_details';

    protected $fillable = [
        'team_id', 'plan_id', 'row_key', 'bucket_key', 'channel', 'level', 'label', 'value', 'ordinal',
    ];

    protected $casts = [
        'value' => 'float',
        'ordinal' => 'integer',
        'level' => TimeLevel::class,
    ];

    public function plan()
    {
        return $this->belongsTo(ForecastPlan::class, 'plan_id');
    }
}
