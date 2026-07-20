<?php

namespace Platform\Forecast\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Symfony\Component\Uid\UuidV7;

/**
 * Plan-Typ (Vorlage). Definiert zusammen mit seinen Typ-Zeilen die
 * gemeinsame Struktur aller Instanzen dieses Typs.
 */
class ForecastPlanType extends Model
{
    use SoftDeletes;

    protected $table = 'forecast_plan_types';

    protected $fillable = [
        'uuid', 'team_id', 'user_id', 'key', 'name', 'description', 'config',
    ];

    protected $casts = [
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

    public function team()
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class);
    }

    public function user()
    {
        return $this->belongsTo(\Platform\Core\Models\User::class);
    }

    /** Zeilen der Vorlage (plan_id null). */
    public function rows()
    {
        return $this->hasMany(ForecastRow::class, 'plan_type_id');
    }

    public function plans()
    {
        return $this->hasMany(ForecastPlan::class, 'plan_type_id');
    }
}
