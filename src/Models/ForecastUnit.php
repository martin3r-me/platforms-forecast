<?php

namespace Platform\Forecast\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Symfony\Component\Uid\UuidV7;

/**
 * Einheit mit Umrechnung. team_id null = global. Pflege später via Settings.
 */
class ForecastUnit extends Model
{
    use SoftDeletes;

    protected $table = 'forecast_units';

    protected $fillable = [
        'uuid', 'team_id', 'code', 'name', 'symbol', 'dimension',
        'factor_to_base', 'is_base', 'sort_order',
    ];

    protected $casts = [
        'factor_to_base' => 'float',
        'is_base' => 'boolean',
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

    /** Einheit per Code auflösen (Team-spezifisch bevorzugt, sonst global). */
    public static function resolve(string $code, ?int $teamId): ?self
    {
        return static::where('code', $code)
            ->where(fn ($q) => $q->where('team_id', $teamId)->orWhereNull('team_id'))
            ->orderByRaw('team_id is null')  // Team-spezifisch zuerst
            ->first();
    }
}
