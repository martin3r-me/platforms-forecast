<?php

namespace Platform\Forecast\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Symfony\Component\Uid\UuidV7;

/**
 * Ein Beitrag in einem Kommentar-Thread an einer Zelle oder Zeile einer Planung.
 *
 * Anker = (plan_id, row_key, bucket_key). bucket_key NULL → Kommentar an der ganzen Zeile,
 * sonst an genau einer Zelle (Zeile × Zeitraum). Mehrere Beiträge am selben Anker = Verlauf.
 */
class ForecastComment extends Model
{
    use SoftDeletes;

    protected $table = 'forecast_comments';

    protected $fillable = [
        'uuid', 'team_id', 'plan_id', 'row_key', 'bucket_key', 'user_id', 'body',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
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

    public function user()
    {
        return $this->belongsTo(\Platform\Core\Models\User::class, 'user_id');
    }
}
