<?php

namespace Platform\Forecast\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Platform\Forecast\Enums\TimeLevel;
use Symfony\Component\Uid\UuidV7;

/**
 * Verteilungsschlüssel (entkoppelt, benannt). Bestimmt die Gewichte, mit denen ein
 * gröberer Wert / der Rest nach unten auf feinere Zellen fällt. team_id null = global.
 */
class ForecastDistributionPolicy extends Model
{
    use SoftDeletes;

    protected $table = 'forecast_distribution_policies';

    protected $fillable = ['uuid', 'team_id', 'name', 'key', 'weights', 'is_default', 'config'];

    protected $casts = [
        'weights' => 'array',
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
            ->orderByRaw('team_id is null')
            ->first();
    }

    /** 12 Monatsgewichte (relativ); bei 'even' oder fehlend alle gleich 1. */
    public function monthWeights(): array
    {
        $w = $this->key === 'seasonal' ? ($this->weights ?? []) : [];
        if (count($w) !== 12) {
            return array_fill(0, 12, 1.0);
        }
        return array_map(fn ($x) => (float) $x, $w);
    }

    /** Relatives Gewicht eines Zeit-Buckets aus den Monatsgewichten (Quartal = Σ seiner Monate usw.). */
    public function weightForBucket(string $bucket): float
    {
        $mw = $this->monthWeights();
        $level = TimeLevel::fromKey($bucket);

        return match ($level) {
            TimeLevel::Year => array_sum($mw),
            TimeLevel::Quarter => (function () use ($bucket, $mw) {
                [, $q] = explode('-Q', $bucket);
                $start = ((int) $q - 1) * 3; // 0-basiert
                return $mw[$start] + $mw[$start + 1] + $mw[$start + 2];
            })(),
            TimeLevel::Month => $mw[((int) substr($bucket, 5, 2)) - 1] ?? 1.0,
            TimeLevel::Day => (function () use ($bucket, $mw) {
                $m = (int) substr($bucket, 5, 2);
                $days = Carbon::createFromFormat('Y-m-d', $bucket)->daysInMonth;
                return ($mw[$m - 1] ?? 1.0) / max(1, $days);
            })(),
            TimeLevel::Hour => (function () use ($bucket, $mw) {
                $m = (int) substr($bucket, 5, 2);
                return ($mw[$m - 1] ?? 1.0) / 24.0;
            })(),
        };
    }
}
